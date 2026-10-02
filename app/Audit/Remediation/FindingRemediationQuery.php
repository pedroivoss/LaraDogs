<?php

namespace App\Audit\Remediation;

use App\Audit\Findings\ScanStatus;
use App\Audit\QualityGates\QualityGateOutcome;
use App\Audit\QualityGates\Query\ProjectQualityGateQuery;
use App\Audit\Source\Git\GitRepositoryInspector;
use App\Audit\Source\Git\GitSnapshot;
use App\Models\Audit\Finding;
use App\Models\Audit\Scan;
use Throwable;

/**
 * Loads the bounded, persisted facts a remediation plan needs (Phase 12) —
 * strictly separate from planning ({@see RemediationPlanner}).
 *
 * Query budget per finding (constant, independent of list sizes): the finding
 * (+ project, last-seen scan), its newest occurrence, the project's newest
 * terminal scan, and that scan's persisted gate result with its rule results.
 * The only non-database work is ONE call to the existing bounded, read-only
 * Phase 9 Git inspector for the CURRENT source; it is skipped on request.
 * Never called per row of a list.
 */
final class FindingRemediationQuery
{
    public function __construct(
        private readonly GitRepositoryInspector $git,
        private readonly ProjectQualityGateQuery $gates,
    ) {}

    public function evidenceFor(Finding $finding, bool $inspectCurrentSource = true): RemediationEvidence
    {
        $finding->loadMissing(['project', 'lastSeenScan']);
        $project = $finding->project;
        $lastSeen = $finding->lastSeenScan;

        $occurrence = $finding->occurrences()->orderByDesc('id')->first();

        return new RemediationEvidence(
            findingId: $finding->public_id,
            projectId: $project->public_id,
            projectRoot: $project->path,
            ruleId: $finding->rule_id,
            analyzerId: $finding->analyzer_id,
            category: $finding->category,
            severity: $finding->severity,
            confidence: $finding->confidence,
            status: $finding->status,
            title: $finding->title,
            description: $finding->description,
            impact: $finding->impact,
            cwe: $finding->cwe,
            cve: $finding->cve,
            references: array_values(array_filter($finding->references ?? [], 'is_string')),
            metadata: is_array($finding->metadata) ? $finding->metadata : [],
            filePath: $occurrence?->file_path,
            lineStart: $occurrence?->line_start,
            lineEnd: $occurrence?->line_end,
            snippet: $occurrence?->code_snippet,
            ruleVersion: $occurrence?->rule_version,
            analyzerVersion: $occurrence?->analyzer_version,
            profile: $lastSeen !== null && $lastSeen->project_profile !== [] ? $lastSeen->project_profile : null,
            observedSource: $lastSeen === null ? null : GitSnapshot::fromScan($lastSeen),
            currentSource: $inspectCurrentSource ? $this->currentSource($project->path) : null,
            gate: $this->gateFacts($finding),
        );
    }

    private function currentSource(string $path): GitSnapshot
    {
        try {
            return $this->git->inspect($path);
        } catch (Throwable) {
            return GitSnapshot::unavailable('inspection_failed');
        }
    }

    /**
     * The persisted gate result of the newest TERMINAL scan (as the Dashboard
     * shows it) reduced to what impact assessment needs. Null = not evaluated.
     */
    private function gateFacts(Finding $finding): ?GateFacts
    {
        $scan = Scan::query()
            ->where('project_id', $finding->project_id)
            ->whereIn('status', [ScanStatus::Completed, ScanStatus::Failed])
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();

        $result = $scan === null ? null : $this->gates->resultForScan($scan);

        if ($scan === null || $result === null) {
            return null;
        }

        $failed = [];

        foreach ($result->ruleResults as $rule) {
            if ($rule->outcome === QualityGateOutcome::Failed && $rule->finding_ids !== null && $rule->finding_ids !== []) {
                $failed[] = ['finding_ids' => array_values(array_filter($rule->finding_ids, 'is_string')), 'finding_count' => (int) $rule->finding_count];
            }
        }

        return new GateFacts($scan->public_id, $result->evaluated_at->toIso8601String(), $failed);
    }
}
