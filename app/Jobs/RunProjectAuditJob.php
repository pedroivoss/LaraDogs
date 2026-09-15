<?php

namespace App\Jobs;

use App\Audit\Findings\Ingestion\ScanRecorder;
use App\Audit\Findings\ScanStatus;
use App\Audit\Projects\RunProjectAudit;
use App\Models\Audit\Project;
use App\Models\Audit\Scan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Thin queue adapter over {@see RunProjectAudit::execute()} — carries
 * only the Scan's stable internal id (Eloquent's `SerializesModels`
 * re-fetches a FRESH `Scan`/`Project` at execution time, never the
 * in-memory state from when this job was dispatched — no
 * `ProjectProfile`, filesystem tree, or analyzer instance is ever
 * serialized into the `jobs` table payload). All audit-domain logic
 * stays in `RunProjectAudit`/`ScanRunner`/`ScanRecorder` — this class
 * adds none.
 *
 * `$tries = 1` (see `RunProjectAudit`'s own docblock on why unattended
 * retries of an expensive, possibly-20-minute audit are NOT the default
 * here): a failed audit becomes a `Failed` Scan an operator can act on
 * (re-run manually), not an automatic retry storm. `$timeout` is set
 * well above `laradogs.semgrep.timeout_seconds` (1800s default) — see
 * `docker-compose.yml`'s `worker` service for the matching
 * `queue:work --timeout` and why it must stay in sync.
 */
final class RunProjectAuditJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 2000;

    public function __construct(private readonly int $scanId) {}

    public function handle(RunProjectAudit $runner): void
    {
        $scan = Scan::query()->find($this->scanId);

        // The scan (or its project) no longer exists, or was already
        // reclaimed as stale by the time this job was picked up — nothing
        // safe to do; never fabricate/resume a scan that isn't genuinely
        // still Queued.
        if ($scan === null || $scan->status !== ScanStatus::Queued) {
            return;
        }

        $project = Project::query()->find($scan->project_id);

        if ($project === null) {
            return;
        }

        $runner->execute($scan);
    }

    /**
     * Laravel calls this when the job ultimately fails (an uncaught
     * exception anywhere in {@see handle()}, `$tries` exhausted). Belt
     * and suspenders on top of `ScanRecorder::completeScan()`'s own
     * internal try/catch (which already handles the expected "the
     * persistence transaction itself failed" case): this closes the
     * residual gap where an exception originates somewhere else in the
     * glue code between `beginRunning()` and `completeScan()` — a scan
     * must never be left `Queued`/`Running` forever just because the
     * job that was supposed to finish it crashed unexpectedly.
     */
    public function failed(?Throwable $exception): void
    {
        $scan = Scan::query()->find($this->scanId);

        if ($scan === null || in_array($scan->status, [ScanStatus::Completed, ScanStatus::Failed], true)) {
            return;
        }

        app(ScanRecorder::class)->failScan($scan);
    }
}
