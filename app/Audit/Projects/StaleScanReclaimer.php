<?php

namespace App\Audit\Projects;

use App\Audit\Findings\Ingestion\FindingReconciler;
use App\Audit\Findings\ScanStatus;
use App\Models\Audit\Project;
use App\Models\Audit\Scan;

/**
 * Closes the Phase 3.2 known limitation ("a crashed/interrupted process
 * can leave a Scan stuck in `running` status indefinitely") with the
 * smallest portable mechanism available — a deterministic age threshold,
 * no Redis, no distributed locking, no background worker/cron required
 * (though one could call this safely from a scheduled command later; none
 * is added this phase).
 *
 * A `Scan` still `running` after
 * `config('laradogs.projects.stale_scan_threshold_seconds')` seconds since
 * `started_at` is treated as abandoned, not genuinely still executing —
 * an inherent, documented trade-off: a legitimately very slow scan past
 * the threshold would be misclassified too. There is no heartbeat/PID
 * tracking to distinguish the two more precisely without new
 * infrastructure this phase deliberately does not introduce.
 *
 * Reclaiming a stale scan ONLY changes that Scan row's own `status`/
 * `finished_at` — it never touches `Finding`/`FindingOccurrence`/
 * `FindingStatusHistory` in any way, and never calls
 * {@see FindingReconciler}. Fail-closed: a
 * scan that never completed successfully must never be read as having
 * verified anything, and no finding is ever resolved merely because a
 * worker died.
 */
final readonly class StaleScanReclaimer
{
    /**
     * Reclaims the project's stale `running` scan, if one exists.
     *
     * @return Scan|null the reclaimed scan (now `failed`), or null if
     *                   there was no `running` scan, or the running scan
     *                   is not yet stale
     */
    public function reclaimIfStale(Project $project): ?Scan
    {
        $threshold = (int) config('laradogs.projects.stale_scan_threshold_seconds', 3600);

        $scan = Scan::query()
            ->where('project_id', $project->id)
            ->where('status', ScanStatus::Running)
            ->where('started_at', '<', now()->subSeconds($threshold))
            ->first();

        if ($scan === null) {
            return null;
        }

        $scan->status = ScanStatus::Failed;
        $scan->finished_at = now();
        $scan->save();

        return $scan;
    }
}
