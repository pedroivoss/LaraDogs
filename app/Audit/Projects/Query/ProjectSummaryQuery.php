<?php

namespace App\Audit\Projects\Query;

use App\Audit\Findings\FindingStatus;
use App\Audit\Findings\Ingestion\ScanRecorder;
use App\Models\Audit\Finding;
use App\Models\Audit\Project;

/**
 * Builds {@see ProjectSummary}. Mirrors the exact in-memory `countBy()`
 * idiom {@see ScanRecorder::summarize()}
 * already uses for per-scan severity breakdowns — kept portable (no raw
 * SQL / vendor `GROUP BY` on a cast enum column) and consistent with
 * existing code rather than introducing a second aggregation style.
 */
final class ProjectSummaryQuery
{
    public function forProject(Project $project): ProjectSummary
    {
        $totalFindings = Finding::query()->where('project_id', $project->id)->count();

        $openFindings = Finding::query()
            ->where('project_id', $project->id)
            ->whereIn('status', [FindingStatus::Open, FindingStatus::Confirmed])
            ->get();

        $lastScan = $project->latestScan()->with('analyzerExecutions')->first();

        $analyzerStatuses = [];

        if ($lastScan !== null) {
            foreach ($lastScan->analyzerExecutions as $execution) {
                $analyzerStatuses[$execution->analyzer_id] = $execution->status->value;
            }
        }

        return new ProjectSummary(
            totalFindings: $totalFindings,
            openFindings: $openFindings->count(),
            openFindingsBySeverity: $openFindings->countBy(fn (Finding $f) => $f->severity->value)->all(),
            openFindingsByCategory: $openFindings->countBy(fn (Finding $f) => $f->category->value)->all(),
            lastScanAnalyzerStatuses: $analyzerStatuses,
            lastScan: $lastScan,
        );
    }
}
