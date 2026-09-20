<?php

namespace App\Audit\Projects\Query;

use App\Audit\Findings\FindingStatus;
use App\Audit\Findings\Ingestion\ScanRecorder;
use App\Audit\Findings\ScanStatus;
use App\Models\Audit\Finding;
use App\Models\Audit\Project;
use App\Models\Audit\Scan;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds {@see ProjectSummary}. Mirrors the exact in-memory `countBy()`
 * idiom {@see ScanRecorder::summarize()}
 * already uses for per-scan severity breakdowns — kept portable (no raw
 * SQL / vendor `GROUP BY` on a cast enum column) and consistent with
 * existing code rather than introducing a second aggregation style.
 *
 * Historical context is selected from TERMINAL scans only (Phase
 * 7.1.4.1): a `Queued`/`Running` scan has no analyzer executions (they
 * are persisted when a scan completes) and, while `Queued`, only a
 * placeholder empty `project_profile` — letting it stand in as "the
 * latest scan" made Project Detail claim a previously-audited project
 * had "not been scanned yet" for the whole duration of a new audit.
 * Selection rules:
 *
 * - `lastScan` — newest `Completed`/`Failed` scan ("the last audit").
 * - `lastCompletedScan` — newest `Completed` scan; the only source of
 *   analyzer status. A `Failed` scan persists no executions, so it can
 *   never be an analyzer-status source (and is never read as clean).
 * - `profile` — newest NON-EMPTY project-profile snapshot among terminal
 *   scans (a `Failed` scan that reached `Running` still recorded a real
 *   one; one that failed before discovery only has the empty placeholder).
 *
 * Ordering matches {@see ScanHistoryQuery}: `started_at` then `id` (the
 * timestamp is only second-precision).
 */
final class ProjectSummaryQuery
{
    /** How many recent terminal scans to inspect for a non-empty profile. */
    private const PROFILE_LOOKBACK = 10;

    public function forProject(Project $project): ProjectSummary
    {
        $totalFindings = Finding::query()->where('project_id', $project->id)->count();

        $openFindings = Finding::query()
            ->where('project_id', $project->id)
            ->whereIn('status', [FindingStatus::Open, FindingStatus::Confirmed])
            ->get();

        $lastScan = $this->newestFirst($project, [ScanStatus::Completed, ScanStatus::Failed])->first();

        $lastCompletedScan = $lastScan?->status === ScanStatus::Completed
            ? $lastScan
            : $this->newestFirst($project, [ScanStatus::Completed])->first();

        $lastCompletedScan?->load('analyzerExecutions');

        $analyzerStatuses = [];

        if ($lastCompletedScan !== null) {
            foreach ($lastCompletedScan->analyzerExecutions as $execution) {
                $analyzerStatuses[$execution->analyzer_id] = $execution->status->value;
            }
        }

        return new ProjectSummary(
            totalFindings: $totalFindings,
            openFindings: $openFindings->count(),
            openFindingsBySeverity: $openFindings->countBy(fn (Finding $f) => $f->severity->value)->all(),
            openFindingsByCategory: $openFindings->countBy(fn (Finding $f) => $f->category->value)->all(),
            lastCompletedScanAnalyzerStatuses: $analyzerStatuses,
            lastScan: $lastScan,
            lastCompletedScan: $lastCompletedScan,
            profile: $this->latestValidProfile($project),
        );
    }

    /**
     * @param  list<ScanStatus>  $statuses
     * @return Builder<Scan>
     */
    private function newestFirst(Project $project, array $statuses): Builder
    {
        return Scan::query()
            ->where('project_id', $project->id)
            ->whereIn('status', $statuses)
            ->orderByDesc('started_at')
            ->orderByDesc('id');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function latestValidProfile(Project $project): ?array
    {
        $scan = $this->newestFirst($project, [ScanStatus::Completed, ScanStatus::Failed])
            ->limit(self::PROFILE_LOOKBACK)
            ->get(['id', 'project_profile'])
            ->first(fn (Scan $scan): bool => $scan->project_profile !== []);

        return $scan?->project_profile;
    }
}
