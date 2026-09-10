<?php

namespace App\Audit\Projects\Query;

use App\Models\Audit\Finding;
use App\Models\Audit\FindingOccurrence;
use App\Models\Audit\Project;
use App\Models\Audit\Scan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * "Current findings" for a Project are simply its {@see Finding} rows —
 * each one already IS the current logical state of an issue (status,
 * severity, last-seen); {@see FindingOccurrence} is the
 * per-scan evidence history, not a separate "current" concept. No implicit
 * status filter is applied by default (e.g. resolved findings are
 * included) — callers decide what "current" means for their use case via
 * {@see FindingFilters}.
 */
final class CurrentFindingsQuery
{
    /**
     * @return Collection<int, Finding> newest-last-seen first
     */
    public function forProject(Project $project, ?FindingFilters $filters = null): Collection
    {
        $query = Finding::query()->where('project_id', $project->id);

        $this->applyFilters($query, $filters);

        return $query->orderByDesc('last_seen_at')->get();
    }

    /**
     * Same data as {@see forProject()}, server-side paginated — for a
     * findings BROWSER (e.g. the Dashboard), which must never load an
     * unbounded list into memory. Reads the current page from the
     * request's own `page` query parameter (Laravel's standard
     * pagination convention), so callers don't pass it explicitly.
     *
     * @return LengthAwarePaginator<int, Finding>
     */
    public function paginateForProject(Project $project, ?FindingFilters $filters = null, int $perPage = 25): LengthAwarePaginator
    {
        $query = Finding::query()->where('project_id', $project->id);

        $this->applyFilters($query, $filters);

        return $query->orderByDesc('last_seen_at')->orderByDesc('id')->paginate($perPage)->withQueryString();
    }

    /**
     * Findings actually observed IN this specific scan (via their
     * {@see FindingOccurrence}) — distinct from
     * `forProject()`, which returns a Project's current findings
     * regardless of which scan last observed them.
     *
     * @return Collection<int, Finding>
     */
    public function forScan(Scan $scan): Collection
    {
        return Finding::query()
            ->whereHas('occurrences', function ($query) use ($scan): void {
                $query->where('scan_id', $scan->id);
            })
            ->orderByDesc('last_seen_at')
            ->get();
    }

    /**
     * @param  Builder<Finding>  $query
     */
    private function applyFilters(Builder $query, ?FindingFilters $filters): void
    {
        if ($filters === null) {
            return;
        }

        if ($filters->status !== null) {
            $query->whereIn('status', $filters->status);
        }

        if ($filters->severity !== null) {
            $query->whereIn('severity', $filters->severity);
        }

        if ($filters->category !== null) {
            $query->whereIn('category', $filters->category);
        }

        if ($filters->analyzerId !== null) {
            $query->where('analyzer_id', $filters->analyzerId);
        }

        if ($filters->ruleId !== null) {
            $query->where('rule_id', $filters->ruleId);
        }
    }
}
