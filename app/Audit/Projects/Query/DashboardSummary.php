<?php

namespace App\Audit\Projects\Query;

use App\Models\Audit\Scan;
use App\Models\Audit\ScanAnalyzerExecution;
use Illuminate\Support\Collection;

/**
 * Cross-project aggregate for the Dashboard home — see
 * {@see DashboardSummaryQuery}. No health score, no invented trend
 * (there is no historical-comparison semantics yet to compute one from).
 */
final readonly class DashboardSummary
{
    /**
     * @param  Collection<int, Scan>  $recentScans  newest first, across all projects, `project` eager-loaded
     * @param  Collection<int, ScanAnalyzerExecution>  $analyzerProblems  Failed/TimedOut executions among the recent scans, `scan.project` eager-loaded
     */
    public function __construct(
        public int $totalProjects,
        public int $projectsWithOpenFindings,
        public int $totalOpenFindings,
        public int $criticalOpenFindings,
        public int $highOpenFindings,
        public Collection $recentScans,
        public Collection $analyzerProblems,
    ) {}
}
