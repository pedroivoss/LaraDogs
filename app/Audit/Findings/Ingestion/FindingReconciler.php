<?php

namespace App\Audit\Findings\Ingestion;

use App\Audit\Engine\Execution\AnalyzerCoverage;
use App\Audit\Engine\Execution\CoverageMode;
use App\Audit\Engine\Execution\ExecutionStatus;
use App\Audit\Findings\ActorType;
use App\Audit\Findings\FindingStatus;
use App\Audit\Findings\Lifecycle\FindingLifecycleService;
use App\Models\Audit\Finding;
use App\Models\Audit\FindingOccurrence;
use App\Models\Audit\Project;
use App\Models\Audit\Scan;
use App\Models\Audit\ScanAnalyzerExecution;

/**
 * Decides which open/confirmed findings can safely be auto-resolved after
 * a scan finished ingesting its observations — the safety-critical half of
 * the lifecycle (see docs/auditing/findings-lifecycle.md#auto-resolution-safety).
 *
 * A finding is only ever auto-resolved when ALL of the following hold:
 *
 * 1. Its OWN analyzer completed this scan with {@see ExecutionStatus::Passed}.
 * 2. That execution's declared {@see AnalyzerCoverage}
 *    actually verifies the finding's `rule_id` — `Passed` alone is NOT
 *    sufficient. An analyzer can run cleanly while the specific rule
 *    behind an old finding was removed, disabled, or simply not loaded
 *    this run; only explicit coverage evidence can rule that out.
 * 3. It was not re-observed in this scan.
 *
 * An analyzer that didn't run, wasn't applicable, was unavailable, failed,
 * timed out, or ran with unknown/insufficient coverage gives NO evidence
 * the underlying issue is gone — findings belonging to it are left
 * untouched, deliberately. Fail closed.
 */
final class FindingReconciler
{
    public function __construct(private readonly FindingLifecycleService $lifecycle) {}

    /**
     * @return int the number of findings auto-resolved
     */
    public function reconcile(Project $project, Scan $scan): int
    {
        $passedExecutions = ScanAnalyzerExecution::query()
            ->where('scan_id', $scan->id)
            ->where('status', ExecutionStatus::Passed)
            ->get();

        if ($passedExecutions->isEmpty()) {
            return 0;
        }

        $observedFindingIds = FindingOccurrence::query()
            ->where('scan_id', $scan->id)
            ->pluck('finding_id');

        $resolvedCount = 0;

        foreach ($passedExecutions as $execution) {
            $coverage = $execution->coverage;

            // Passed-but-unknown-coverage is exactly the case this phase
            // exists to close: an analyzer running cleanly says nothing,
            // by itself, about which rules it actually verified.
            if ($coverage->mode === CoverageMode::Unknown) {
                continue;
            }

            $candidates = Finding::query()
                ->where('project_id', $project->id)
                ->where('analyzer_id', $execution->analyzer_id)
                ->whereIn('status', [FindingStatus::Open, FindingStatus::Confirmed])
                ->whereNotIn('id', $observedFindingIds)
                ->get();

            foreach ($candidates as $finding) {
                if (! $coverage->verifies($finding->rule_id)) {
                    continue;
                }

                $this->lifecycle->transition(
                    $finding,
                    FindingStatus::Resolved,
                    ActorType::System,
                    reason: "Auto-resolved: analyzer [{$finding->analyzer_id}] verified rule [{$finding->rule_id}] (coverage: {$coverage->mode->value}) in scan {$scan->public_id} and no longer reports this finding.",
                    scan: $scan,
                );

                $resolvedCount++;
            }
        }

        return $resolvedCount;
    }
}
