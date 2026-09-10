<?php

namespace App\Http\Controllers;

use App\Audit\Projects\Query\DashboardSummary;
use App\Audit\Projects\Query\DashboardSummaryQuery;
use App\Models\Audit\Scan;
use App\Models\Audit\ScanAnalyzerExecution;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Thin adapter over {@see DashboardSummaryQuery} — no aggregation logic of
 * its own beyond serializing the DTO into Inertia props.
 */
final class DashboardController extends Controller
{
    public function __invoke(DashboardSummaryQuery $query): Response
    {
        return Inertia::render('dashboard', [
            'summary' => $this->toArray($query->summary()),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function toArray(DashboardSummary $summary): array
    {
        return [
            'total_projects' => $summary->totalProjects,
            'projects_with_open_findings' => $summary->projectsWithOpenFindings,
            'total_open_findings' => $summary->totalOpenFindings,
            'critical_open_findings' => $summary->criticalOpenFindings,
            'high_open_findings' => $summary->highOpenFindings,
            'recent_scans' => $summary->recentScans->map($this->scanToArray(...))->all(),
            'analyzer_problems' => $summary->analyzerProblems->map($this->executionToArray(...))->all(),
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
            'project' => [
                'id' => $scan->project->public_id,
                'name' => $scan->project->name,
            ],
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
            'status' => $execution->status->value,
            'scan' => [
                'id' => $execution->scan->public_id,
                'project' => [
                    'id' => $execution->scan->project->public_id,
                    'name' => $execution->scan->project->name,
                ],
            ],
        ];
    }
}
