<?php

namespace App\Audit\Projects\Query;

use App\Audit\Findings\FindingStatus;
use App\Models\Audit\Project;
use Illuminate\Database\Eloquent\Collection;

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
        return Project::query()
            ->with('latestScan')
            ->withCount(['findings as open_findings_count' => function ($query): void {
                $query->whereIn('status', [FindingStatus::Open, FindingStatus::Confirmed]);
            }])
            ->orderBy('name')
            ->get();
    }
}
