<?php

namespace App\Http\Controllers\Projects;

use App\Audit\Projects\Query\CurrentFindingsQuery;
use App\Audit\Projects\Query\ProjectListQuery;
use App\Audit\Projects\Query\ProjectSummary;
use App\Audit\Projects\Query\ProjectSummaryQuery;
use App\Audit\Projects\Query\ScanHistoryQuery;
use App\Http\Controllers\Controller;
use App\Models\Audit\Finding;
use App\Models\Audit\Project;
use App\Models\Audit\Scan;
use App\Models\Audit\ScanAnalyzerExecution;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Thin adapter over the existing project/query/summary layer — no audit
 * logic, no direct Eloquent business queries beyond eager-loading the
 * Project model's own already-defined `latestScan` relation for the
 * analyzer-execution detail the summary DTO doesn't itself carry.
 */
final class ProjectsController extends Controller
{
    public function index(ProjectListQuery $query): Response
    {
        $projects = $query->all();

        return Inertia::render('projects/index', [
            'projects' => $projects->map($this->projectListItemToArray(...))->all(),
        ]);
    }

    public function show(
        Project $project,
        ProjectSummaryQuery $summaryQuery,
        ScanHistoryQuery $scanHistoryQuery,
        CurrentFindingsQuery $findingsQuery,
    ): Response {
        $summary = $summaryQuery->forProject($project);
        $latestScan = $project->latestScan()->with('analyzerExecutions')->first();
        $recentScans = $scanHistoryQuery->recentFor($project, limit: 5);
        $recentFindings = $findingsQuery->paginateForProject($project, perPage: 10);

        return Inertia::render('projects/show', [
            'project' => [
                'id' => $project->public_id,
                'name' => $project->name,
                'path' => $project->path,
            ],
            'profile' => $latestScan?->project_profile,
            'summary' => $this->summaryToArray($summary),
            'analyzer_executions' => $latestScan?->analyzerExecutions->map($this->executionToArray(...))->all() ?? [],
            'recent_scans' => $recentScans->map($this->scanToArray(...))->all(),
            'recent_findings' => $recentFindings->getCollection()->map($this->findingToArray(...))->all(),
            'audit_command' => "php artisan laradogs:project:audit {$project->public_id}",
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function projectListItemToArray(Project $project): array
    {
        return [
            'id' => $project->public_id,
            'name' => $project->name,
            'path' => $project->path,
            'open_findings_count' => $project->open_findings_count,
            'last_scan' => $project->latestScan === null ? null : [
                'id' => $project->latestScan->public_id,
                'status' => $project->latestScan->status->value,
                'started_at' => $project->latestScan->started_at->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function summaryToArray(ProjectSummary $summary): array
    {
        return [
            'total_findings' => $summary->totalFindings,
            'open_findings' => $summary->openFindings,
            'open_findings_by_severity' => $summary->openFindingsBySeverity,
            'open_findings_by_category' => $summary->openFindingsByCategory,
            'last_scan_analyzer_statuses' => $summary->lastScanAnalyzerStatuses,
            'last_scan' => $summary->lastScan === null ? null : $this->scanToArray($summary->lastScan),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function scanToArray(Scan $scan): array
    {
        return [
            'id' => $scan->public_id,
            'status' => $scan->status->value,
            'started_at' => $scan->started_at->toIso8601String(),
            'finished_at' => $scan->finished_at?->toIso8601String(),
            'duration_ms' => $scan->duration_ms,
            'findings_summary' => $scan->findings_summary,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function executionToArray(ScanAnalyzerExecution $execution): array
    {
        return [
            'analyzer_id' => $execution->analyzer_id,
            'analyzer_name' => $execution->analyzer_name,
            'category' => $execution->category->value,
            'status' => $execution->status->value,
            'summary' => $execution->summary,
            'duration_ms' => $execution->duration_ms,
            'note' => $execution->note,
            'coverage' => [
                'mode' => $execution->coverage->mode->value,
                'rule_ids' => $execution->coverage->ruleIds,
            ],
            'diagnostics' => $execution->diagnostics,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function findingToArray(Finding $finding): array
    {
        return [
            'id' => $finding->public_id,
            'rule_id' => $finding->rule_id,
            'analyzer_id' => $finding->analyzer_id,
            'category' => $finding->category->value,
            'severity' => $finding->severity->value,
            'confidence' => $finding->confidence->value,
            'status' => $finding->status->value,
            'title' => $finding->title,
            'last_seen_at' => $finding->last_seen_at->toIso8601String(),
        ];
    }
}
