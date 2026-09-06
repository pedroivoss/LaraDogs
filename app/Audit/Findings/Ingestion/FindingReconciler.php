<?php

namespace App\Audit\Findings\Ingestion;

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
 * A finding is only ever auto-resolved when its OWN analyzer completed
 * this scan with {@see ExecutionStatus::Passed} and the finding was not
 * re-observed. An analyzer that didn't run, wasn't applicable, was
 * unavailable, failed, or timed out gives NO evidence the underlying issue
 * is gone — findings belonging to it are left untouched, deliberately.
 */
final class FindingReconciler
{
    public function __construct(private readonly FindingLifecycleService $lifecycle) {}

    /**
     * @return int the number of findings auto-resolved
     */
    public function reconcile(Project $project, Scan $scan): int
    {
        $reliableAnalyzerIds = ScanAnalyzerExecution::query()
            ->where('scan_id', $scan->id)
            ->where('status', ExecutionStatus::Passed)
            ->pluck('analyzer_id');

        if ($reliableAnalyzerIds->isEmpty()) {
            return 0;
        }

        $observedFindingIds = FindingOccurrence::query()
            ->where('scan_id', $scan->id)
            ->pluck('finding_id');

        $staleFindings = Finding::query()
            ->where('project_id', $project->id)
            ->whereIn('status', [FindingStatus::Open, FindingStatus::Confirmed])
            ->whereIn('analyzer_id', $reliableAnalyzerIds)
            ->whereNotIn('id', $observedFindingIds)
            ->get();

        foreach ($staleFindings as $finding) {
            $this->lifecycle->transition(
                $finding,
                FindingStatus::Resolved,
                ActorType::System,
                reason: "Auto-resolved: analyzer [{$finding->analyzer_id}] completed successfully in scan {$scan->public_id} and no longer reports this finding.",
                scan: $scan,
            );
        }

        return $staleFindings->count();
    }
}
