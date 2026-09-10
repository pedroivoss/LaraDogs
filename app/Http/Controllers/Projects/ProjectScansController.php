<?php

namespace App\Http\Controllers\Projects;

use App\Audit\Projects\Query\CurrentFindingsQuery;
use App\Audit\Projects\Query\ScanHistoryQuery;
use App\Http\Controllers\Controller;
use App\Models\Audit\Finding;
use App\Models\Audit\Project;
use App\Models\Audit\Scan;
use App\Models\Audit\ScanAnalyzerExecution;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Thin adapter over {@see ScanHistoryQuery}. A Scan is an immutable
 * historical record — `show()` never substitutes the project's CURRENT
 * state for what was actually observed at that scan (its own
 * `project_profile` snapshot, its own analyzer executions, its own
 * observed findings).
 */
final class ProjectScansController extends Controller
{
    public function index(Project $project, ScanHistoryQuery $query): Response
    {
        $scans = $query->paginateFor($project, perPage: 20);

        return Inertia::render('projects/scans', [
            'project' => [
                'id' => $project->public_id,
                'name' => $project->name,
            ],
            'scans' => $scans->getCollection()->map($this->scanToArray(...))->all(),
            'pagination' => [
                'current_page' => $scans->currentPage(),
                'last_page' => $scans->lastPage(),
                'per_page' => $scans->perPage(),
                'total' => $scans->total(),
            ],
        ]);
    }

    public function show(Project $project, Scan $scan, ScanHistoryQuery $query, CurrentFindingsQuery $findingsQuery): Response
    {
        // Defensive, explicit scoping — never rely solely on Laravel's
        // implicit nested-binding scoping for a security-relevant
        // boundary: a scan must belong to the project in the URL.
        if ($scan->project_id !== $project->id) {
            throw new NotFoundHttpException;
        }

        $detail = $query->detail($scan->public_id);
        $observedFindings = $findingsQuery->forScan($scan);

        return Inertia::render('projects/scan-detail', [
            'project' => [
                'id' => $project->public_id,
                'name' => $project->name,
            ],
            'scan' => [
                'id' => $scan->public_id,
                'status' => $scan->status->value,
                'started_at' => $scan->started_at->toIso8601String(),
                'finished_at' => $scan->finished_at?->toIso8601String(),
                'duration_ms' => $scan->duration_ms,
                'laradogs_version' => $scan->laradogs_version,
                'source_revision' => $scan->source_revision,
                'project_profile' => $scan->project_profile,
                'environment' => $scan->environment,
                'findings_summary' => $scan->findings_summary,
            ],
            'analyzer_executions' => $detail?->analyzerExecutions->map($this->executionToArray(...))->all() ?? [],
            'observed_findings' => $observedFindings->map($this->findingToArray(...))->all(),
        ]);
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
        ];
    }
}
