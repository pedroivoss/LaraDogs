<?php

namespace App\Audit\Projects\Query;

use App\Audit\Findings\FindingStatus;
use App\Models\Audit\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Backs the project list (CLI today; Dashboard/MCP adapters later).
 * Deliberately avoids N+1: one query for the projects themselves, one
 * portable correlated-subquery join for each project's latest Scan (via
 * {@see Project::latestScan()}, Eloquent's `ofMany()` — no vendor-specific
 * window function), one aggregate query for open finding counts.
 */
final class ProjectListQuery
{
    /**
     * @return Collection<int, Project> each with `latestScan` eager-loaded
     *                                  and an `open_findings_count` attribute
     */
    public function all(): Collection
    {
        return $this->query()->get();
    }

    /**
     * The same data, bounded — for adapters that must never load an
     * unbounded list (MCP, Phase 11). Ordered by name, then id (stable).
     *
     * @return LengthAwarePaginator<int, Project>
     */
    public function paginate(int $perPage, int $page): LengthAwarePaginator
    {
        return $this->query()->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @return Builder<Project>
     */
    private function query(): Builder
    {
        return Project::query()
            ->with('latestScan')
            ->withCount(['findings as open_findings_count' => function ($query): void {
                $query->whereIn('status', [FindingStatus::Open, FindingStatus::Confirmed]);
            }])
            ->orderBy('name');
    }
}
