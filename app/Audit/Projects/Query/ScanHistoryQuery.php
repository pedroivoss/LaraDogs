<?php

namespace App\Audit\Projects\Query;

use App\Models\Audit\Project;
use App\Models\Audit\Scan;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Scan history for one Project — recent scans (list) and one scan's full
 * detail (executions). Never deletes/overwrites a Scan; every row this
 * reads is immutable once its status leaves `Running`.
 */
final class ScanHistoryQuery
{
    /**
     * @return Collection<int, Scan> newest first
     */
    public function recentFor(Project $project, int $limit = 20): Collection
    {
        return Scan::query()
            ->where('project_id', $project->id)
            // `id` as a tiebreaker: `started_at` timestamp columns are only
            // second-precision (portable across SQLite/MySQL/MariaDB/
            // PostgreSQL without a vendor-specific microsecond type), so
            // two scans started within the same second would otherwise
            // sort nondeterministically. `id` is monotonically increasing
            // and always resolves the tie correctly.
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Same data as {@see recentFor()}, server-side paginated — for a scan
     * history BROWSER (e.g. the Dashboard). Reads the current page from
     * the request's own `page` query parameter.
     *
     * @return LengthAwarePaginator<int, Scan>
     */
    public function paginateFor(Project $project, int $perPage = 20): LengthAwarePaginator
    {
        return Scan::query()
            ->where('project_id', $project->id)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  string  $publicScanId  a Scan's public ULID, never the
     *                                internal numeric id
     */
    public function detail(string $publicScanId): ?Scan
    {
        return Scan::query()
            ->where('public_id', $publicScanId)
            ->with('analyzerExecutions')
            ->first();
    }
}
