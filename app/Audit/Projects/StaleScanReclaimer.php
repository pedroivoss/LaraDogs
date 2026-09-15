<?php

namespace App\Audit\Projects;

use App\Audit\Findings\Ingestion\FindingReconciler;
use App\Audit\Findings\Ingestion\ScanRecorder;
use App\Audit\Findings\ScanStatus;
use App\Models\Audit\Project;
use App\Models\Audit\Scan;

/**
 * Closes the Phase 3.2 known limitation ("a crashed/interrupted process
 * can leave a Scan stuck indefinitely") with the smallest portable
 * mechanism available — deterministic age thresholds, no Redis, no
 * distributed locking.
 *
 * Phase 7.1.4 (unattended, queued/scheduled execution) splits this into
 * TWO independent thresholds, deliberately not one shared value:
 *
 * - `queued_scan_stale_threshold_seconds` — a `Queued` scan a worker
 *   never picked up at all (worker never started, queue misconfigured,
 *   `jobs` row lost). This should be short: a healthy worker picks up a
 *   queued job within seconds, so anything still `Queued` after minutes
 *   is already abnormal — no reason to wait as long as a genuinely
 *   running Semgrep pass would need.
 * - `stale_scan_threshold_seconds` (unchanged name/config key from Phase
 *   7 — existing installations keep working with no config migration) —
 *   a `Running` scan whose worker died mid-execution. Must stay long
 *   (default 1 hour), matching `laradogs.semgrep.timeout_seconds`'s own
 *   worst-case runtime, exactly as before this phase.
 *
 * Reclaiming a stale scan ONLY changes that Scan row's own status/
 * timestamps and releases its `project_active_scans` mutex row (Phase
 * 7.1.4) — it never touches `Finding`/`FindingOccurrence`/
 * `FindingStatusHistory`, and never calls {@see FindingReconciler}.
 * Fail-closed: a scan that never completed successfully must never be
 * read as having verified anything, and no finding is ever resolved
 * merely because a worker died.
 */
final readonly class StaleScanReclaimer
{
    public function __construct(private ScanRecorder $recorder) {}

    /**
     * Reclaims the project's stale `queued` or `running` scan, if one
     * exists.
     *
     * @return Scan|null the reclaimed scan (now `failed`), or null if
     *                   there was no active scan, or it is not yet stale
     */
    public function reclaimIfStale(Project $project): ?Scan
    {
        return $this->reclaimStaleQueued($project) ?? $this->reclaimStaleRunning($project);
    }

    private function reclaimStaleQueued(Project $project): ?Scan
    {
        $threshold = (int) config('laradogs.projects.queued_scan_stale_threshold_seconds', 120);

        $scan = Scan::query()
            ->where('project_id', $project->id)
            ->where('status', ScanStatus::Queued)
            ->where('started_at', '<', now()->subSeconds($threshold))
            ->first();

        return $scan === null ? null : $this->recorder->failScan($scan);
    }

    private function reclaimStaleRunning(Project $project): ?Scan
    {
        $threshold = (int) config('laradogs.projects.stale_scan_threshold_seconds', 3600);

        $scan = Scan::query()
            ->where('project_id', $project->id)
            ->where('status', ScanStatus::Running)
            ->where(function ($query) use ($threshold) {
                // A missing heartbeat (pre-7.1.4 scans, or a worker that
                // died before its first heartbeat touch) falls back to
                // `started_at` — the same signal this check used before
                // heartbeats existed.
                $query->where('heartbeat_at', '<', now()->subSeconds($threshold))
                    ->orWhere(function ($inner) use ($threshold) {
                        $inner->whereNull('heartbeat_at')
                            ->where('started_at', '<', now()->subSeconds($threshold));
                    });
            })
            ->first();

        return $scan === null ? null : $this->recorder->failScan($scan);
    }
}
