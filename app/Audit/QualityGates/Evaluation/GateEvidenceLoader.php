<?php

namespace App\Audit\QualityGates\Evaluation;

use App\Audit\Findings\FindingStatus;
use App\Audit\Findings\ScanStatus;
use App\Audit\Findings\Severity;
use App\Audit\QualityGates\GateRuleId;
use App\Audit\QualityGates\Policy\MaxOpenFindingsRule;
use App\Audit\QualityGates\Policy\NoNewSeverityRule;
use App\Audit\QualityGates\Policy\QualityGatePolicy;
use App\Audit\Source\SourceIntegrityReason;
use App\Models\Audit\Finding;
use App\Models\Audit\FindingOccurrence;
use App\Models\Audit\Scan;
use App\Models\Audit\ScanAnalyzerExecution;
use Illuminate\Support\Facades\DB;

/**
 * Loads the persisted facts a gate needs — nothing else. Read-only and
 * bounded: counts are database aggregates, finding ids are capped, and a
 * query is only issued for data the enabled rules actually use. Never
 * touches the filesystem, never runs an analyzer, never writes.
 *
 * "Current" open findings are the project's findings in a gate-eligible
 * status (FindingStatus::countsTowardQualityGate()) at evaluation time —
 * which is immediately after the scan finished, so they include findings
 * of analyzers that did not run this scan and simply remain open. The
 * baseline is the previous COMPLETED scan of the project (highest id
 * below the evaluated scan's; scans of one project never overlap): a
 * Queued/Running/Failed scan is never a baseline.
 */
final class GateEvidenceLoader
{
    public function load(Scan $scan, QualityGatePolicy $policy): GateEvidence
    {
        $executions = $this->executionsFor($scan->id);

        $counts = [];
        $ids = [];
        $maxOpen = $policy->rule(GateRuleId::MaxOpenFindings);

        if ($maxOpen instanceof MaxOpenFindingsRule) {
            $counts = $this->openCounts($scan->project_id);

            foreach ($maxOpen->orderedLimits() as [$severity]) {
                if (($counts[$severity->value] ?? 0) > 0) {
                    $ids[$severity->value] = $this->openIds($scan->project_id, $severity);
                }
            }
        }

        $baselineId = null;
        $baselineExecutions = [];
        $candidates = [];
        $noNew = $policy->rule(GateRuleId::NoNewSeverity);

        if ($noNew instanceof NoNewSeverityRule) {
            $baseline = Scan::query()
                ->where('project_id', $scan->project_id)
                ->where('status', ScanStatus::Completed)
                ->where('id', '<', $scan->id)
                ->orderByDesc('id')
                ->first(['id']);

            if ($baseline !== null) {
                $baselineId = $baseline->id;
                $baselineExecutions = $this->executionsFor($baseline->id);
                $candidates = $this->newCandidates($scan, $baseline->id, $noNew->minSeverity);
            }
        }

        return new GateEvidence(
            scanCompleted: $scan->status === ScanStatus::Completed,
            executions: $executions,
            openCountsBySeverity: $counts,
            openFindingIdsBySeverity: $ids,
            baselineScanId: $baselineId,
            baselineExecutions: $baselineExecutions,
            newCandidates: $candidates,
            sourceIntegrityIssue: $scan->source_consistent === false
                ? (SourceIntegrityReason::tryFrom((string) $scan->source_integrity_reason) ?? SourceIntegrityReason::Unavailable)
                : null,
        );
    }

    /**
     * @return array<string,ExecutionEvidence>
     */
    private function executionsFor(int $scanId): array
    {
        $evidence = [];

        foreach (ScanAnalyzerExecution::query()->where('scan_id', $scanId)->get() as $execution) {
            $evidence[$execution->analyzer_id] = new ExecutionEvidence($execution->status, $execution->coverage);
        }

        return $evidence;
    }

    /**
     * @return array<string,int>
     */
    private function openCounts(int $projectId): array
    {
        $rows = DB::table('findings')
            ->where('project_id', $projectId)
            ->whereIn('status', $this->eligibleStatuses())
            ->select('severity', DB::raw('count(*) as aggregate'))
            ->groupBy('severity')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row->severity] = (int) $row->aggregate;
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    private function openIds(int $projectId, Severity $severity): array
    {
        /** @var list<string> $ids */
        $ids = DB::table('findings')
            ->where('project_id', $projectId)
            ->whereIn('status', $this->eligibleStatuses())
            ->where('severity', $severity->value)
            ->orderBy('id')
            ->limit(RuleResult::MAX_FINDING_IDS)
            ->pluck('public_id')
            ->all();

        return $ids;
    }

    /**
     * Gate-eligible findings observed in `$scan` but not in the baseline,
     * at or above `$min` (Unknown severity included — fail closed).
     *
     * @return list<NewFindingCandidate>
     */
    private function newCandidates(Scan $scan, int $baselineId, Severity $min): array
    {
        $severities = array_map(
            fn (Severity $s): string => $s->value,
            array_values(array_filter([...Severity::ranked(), Severity::Unknown], fn (Severity $s): bool => $s->isAtOrAbove($min))),
        );

        $findings = Finding::query()
            ->where('project_id', $scan->project_id)
            ->whereIn('status', $this->eligibleStatuses())
            ->whereIn('severity', $severities)
            ->whereIn('id', FindingOccurrence::query()->where('scan_id', $scan->id)->select('finding_id'))
            ->whereNotIn('id', FindingOccurrence::query()->where('scan_id', $baselineId)->select('finding_id'))
            ->orderBy('id')
            ->get(['id', 'public_id', 'severity', 'analyzer_id', 'rule_id', 'first_seen_scan_id']);

        $candidates = [];

        foreach ($findings as $finding) {
            $candidates[] = new NewFindingCandidate(
                publicId: $finding->public_id,
                severity: $finding->severity,
                analyzerId: $finding->analyzer_id,
                ruleId: $finding->rule_id,
                firstSeenInThisScan: $finding->first_seen_scan_id === $scan->id,
            );
        }

        return $candidates;
    }

    /**
     * @return list<string>
     */
    private function eligibleStatuses(): array
    {
        return array_map(fn (FindingStatus $s): string => $s->value, FindingStatus::qualityGateEligible());
    }
}
