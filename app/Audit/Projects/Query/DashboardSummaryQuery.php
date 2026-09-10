<?php

namespace App\Audit\Projects\Query;

use App\Audit\Engine\Execution\ExecutionStatus;
use App\Audit\Findings\FindingStatus;
use App\Audit\Findings\Severity;
use App\Models\Audit\Finding;
use App\Models\Audit\Project;
use App\Models\Audit\Scan;
use App\Models\Audit\ScanAnalyzerExecution;

/**
 * The one genuinely NEW cross-project aggregate the Dashboard home needs
 * — distinct from {@see ProjectListQuery} (per-project rows) and
 * {@see ProjectSummaryQuery} (one project's own summary), neither of
 * which answer "across every registered project." Counts use SQL
 * aggregation (`count()`/`distinct()->count()`), never a full findings
 * table scan into PHP memory.
 */
final class DashboardSummaryQuery
{
    public function summary(int $recentScansLimit = 10): DashboardSummary
    {
        $openFindings = Finding::query()->whereIn('status', [FindingStatus::Open, FindingStatus::Confirmed]);

        $recentScans = Scan::query()
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->with('project')
            ->limit($recentScansLimit)
            ->get();

        $analyzerProblems = ScanAnalyzerExecution::query()
            ->whereIn('status', [ExecutionStatus::Failed, ExecutionStatus::TimedOut])
            ->whereIn('scan_id', $recentScans->pluck('id'))
            ->with('scan.project')
            ->get();

        return new DashboardSummary(
            totalProjects: Project::query()->count(),
            projectsWithOpenFindings: (clone $openFindings)->distinct('project_id')->count('project_id'),
            totalOpenFindings: (clone $openFindings)->count(),
            criticalOpenFindings: (clone $openFindings)->where('severity', Severity::Critical)->count(),
            highOpenFindings: (clone $openFindings)->where('severity', Severity::High)->count(),
            recentScans: $recentScans,
            analyzerProblems: $analyzerProblems,
        );
    }
}
