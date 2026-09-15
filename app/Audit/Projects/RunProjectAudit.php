<?php

namespace App\Audit\Projects;

use App\Audit\Discovery\ProjectDiscovery;
use App\Audit\Engine\AuditContext;
use App\Audit\Findings\Ingestion\ScanRecorder;
use App\Audit\Findings\Ingestion\ScanRunner;
use App\Audit\Findings\ScanOrigin;
use App\Audit\Findings\ScanStatus;
use App\Models\Audit\Project;
use App\Models\Audit\ProjectActiveScan;
use App\Models\Audit\Scan;
use App\Models\User;
use Illuminate\Database\QueryException;

/**
 * Application-level orchestration for a PERSISTED audit — the ONE
 * canonical entry point every caller (CLI, Dashboard, Scheduler)
 * converges on (Phase 7.1.4). This class adds no persistence logic of
 * its own beyond what it already delegated to before this phase: given a
 * Project already registered with LaraDogs, re-discovers its stack fresh
 * (never trusts the stale profile captured at registration — a project's
 * dependencies/framework version can change between audits, and a Scan
 * must preserve exactly what was observed AT THAT SCAN, not at
 * registration time), then delegates the entire execution+persistence
 * path to the already-existing {@see ScanRunner}/`ScanRecorder`/
 * `FindingIngestor`/`FindingReconciler` pipeline (Phase 3) — never
 * touches `Finding.status` directly (see ADR-0010).
 *
 * Split into two steps because unattended (queued/scheduled) execution
 * cannot run analyzers inside the dispatching HTTP request/scheduler
 * tick:
 *
 * - {@see enqueue()} — reserves a `Queued` Scan (see
 *   {@see ScanRecorder::enqueueScan()}) and returns immediately. Does
 *   NOT run any analyzer, does NOT dispatch a queue job itself (the
 *   caller — `ProjectAuditController`, `DispatchDueProjectAudits`, or
 *   `run()` below — decides whether/how to actually execute it, so this
 *   method alone is safe to call from an HTTP request).
 * - {@see execute()} — takes an already-`Queued` Scan and runs it
 *   (discovery, engine, persistence) to `Completed`/`Failed`. This is
 *   what `App\Jobs\RunProjectAuditJob` calls from the queue worker.
 * - {@see run()} — the CLI's synchronous convenience: `enqueue()`
 *   immediately followed by `execute()` IN THE SAME PROCESS, no real
 *   queue hop — CLI keeps its existing synchronous behavior (valuable
 *   for debugging/automation, see `docs/self-hosting.md`) while still
 *   going through the exact same two steps, and the exact same
 *   concurrency guard, as every other trigger.
 *
 * Concurrency: a portable, DB-enforced mutex (`project_active_scans` —
 * see that migration's own docblock), not merely an advisory query. At
 * most one row can exist per project on ANY supported database, so two
 * nearly-simultaneous `enqueue()` calls for the same project — a
 * double-click, or a manual dispatch racing a scheduled one — can never
 * both succeed: the second's insert hits a real unique-constraint
 * violation, caught here and turned into
 * {@see RunProjectAuditOutcome::AlreadyRunning}, mirroring the same
 * check-then-insert-then-catch pattern {@see RegisterProject}
 * already uses for its own equivalent race. A crashed worker (Queued
 * never picked up, or Running never finished) is reclaimed by
 * {@see StaleScanReclaimer} — called at the START of both `enqueue()`
 * and `execute()`, so a stuck scan can never block a project forever,
 * and its mutex row is released as part of that reclaim.
 */
final readonly class RunProjectAudit
{
    public function __construct(
        private ProjectDiscovery $discovery,
        private ScanRunner $scanRunner,
        private ScanRecorder $recorder,
        private StaleScanReclaimer $staleScanReclaimer,
    ) {}

    public function enqueue(Project $project, ScanOrigin $origin, ?User $actor = null): RunProjectAuditResult
    {
        $this->staleScanReclaimer->reclaimIfStale($project);

        $active = ProjectActiveScan::query()->find($project->id);

        if ($active !== null) {
            $conflictingScan = Scan::query()->find($active->scan_id);

            return RunProjectAuditResult::alreadyRunning($conflictingScan ?? $this->latestActiveScan($project));
        }

        try {
            $scan = $this->recorder->enqueueScan($project, $origin, $actor);
        } catch (QueryException) {
            // Lost a race with a concurrent enqueue() for the same
            // project — the mutex row's unique constraint rejected us,
            // not a real failure (see this class's own docblock).
            return RunProjectAuditResult::alreadyRunning($this->latestActiveScan($project));
        }

        return RunProjectAuditResult::queued($scan);
    }

    public function execute(Scan $scan): RunProjectAuditResult
    {
        $project = $scan->project;

        $discoveryResult = $this->discovery->discover($project->path);

        if ($discoveryResult->profile === null) {
            $this->recorder->failScan($scan);

            return RunProjectAuditResult::pathUnavailable($discoveryResult);
        }

        $context = new AuditContext(
            runId: (string) str()->uuid(),
            projectPath: $discoveryResult->path,
            profile: $discoveryResult->profile,
        );

        $completed = $this->scanRunner->run($scan, $context);

        return RunProjectAuditResult::completed($completed);
    }

    /**
     * The CLI's synchronous convenience — see this class's own docblock.
     */
    public function run(Project $project, ScanOrigin $origin = ScanOrigin::Cli, ?User $actor = null): RunProjectAuditResult
    {
        $enqueueResult = $this->enqueue($project, $origin, $actor);

        if ($enqueueResult->outcome !== RunProjectAuditOutcome::Queued || $enqueueResult->scan === null) {
            return $enqueueResult;
        }

        return $this->execute($enqueueResult->scan);
    }

    private function latestActiveScan(Project $project): ?Scan
    {
        return Scan::query()
            ->where('project_id', $project->id)
            ->whereIn('status', [ScanStatus::Queued, ScanStatus::Running])
            ->latest('id')
            ->first();
    }
}
