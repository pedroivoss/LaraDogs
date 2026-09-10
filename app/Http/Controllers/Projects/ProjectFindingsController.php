<?php

namespace App\Http\Controllers\Projects;

use App\Audit\Engine\Contracts\AnalyzerCategory;
use App\Audit\Findings\FindingStatus;
use App\Audit\Findings\Severity;
use App\Audit\Projects\Query\CurrentFindingsQuery;
use App\Audit\Projects\Query\FindingFilters;
use App\Http\Controllers\Controller;
use App\Models\Audit\Finding;
use App\Models\Audit\Project;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Thin adapter over {@see CurrentFindingsQuery} — filtering/pagination
 * both happen server-side inside that query class; this controller only
 * translates request query params into a {@see FindingFilters} DTO and
 * serializes the resulting page.
 */
final class ProjectFindingsController extends Controller
{
    public function index(Project $project, Request $request, CurrentFindingsQuery $query): Response
    {
        $filters = $this->filtersFromRequest($request);

        $findings = $query->paginateForProject($project, $filters, perPage: 25);

        return Inertia::render('projects/findings', [
            'project' => [
                'id' => $project->public_id,
                'name' => $project->name,
            ],
            'findings' => $findings->getCollection()->map($this->findingToArray(...))->all(),
            'pagination' => [
                'current_page' => $findings->currentPage(),
                'last_page' => $findings->lastPage(),
                'per_page' => $findings->perPage(),
                'total' => $findings->total(),
            ],
            'filters' => [
                'status' => $request->query('status'),
                'severity' => $request->query('severity'),
                'category' => $request->query('category'),
                'analyzer_id' => $request->query('analyzer_id'),
                'rule_id' => $request->query('rule_id'),
            ],
        ]);
    }

    private function filtersFromRequest(Request $request): FindingFilters
    {
        return new FindingFilters(
            status: $this->enumList($request, 'status', FindingStatus::class),
            severity: $this->enumList($request, 'severity', Severity::class),
            category: $this->enumList($request, 'category', AnalyzerCategory::class),
            analyzerId: $this->stringOrNull($request, 'analyzer_id'),
            ruleId: $this->stringOrNull($request, 'rule_id'),
        );
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enumClass
     * @return list<T>|null
     */
    private function enumList(Request $request, string $key, string $enumClass): ?array
    {
        $raw = $request->query($key);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $values = array_filter(array_map(
            fn (string $value) => $enumClass::tryFrom(trim($value)),
            explode(',', $raw),
        ));

        return $values === [] ? null : array_values($values);
    }

    private function stringOrNull(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
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
            'first_seen_at' => $finding->first_seen_at->toIso8601String(),
            'last_seen_at' => $finding->last_seen_at->toIso8601String(),
        ];
    }
}
