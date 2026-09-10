<?php

namespace App\Audit\Projects;

use App\Audit\Discovery\ProjectDiscovery;
use App\Audit\Engine\AuditContext;
use App\Audit\Findings\Ingestion\FindingIngestor;
use App\Audit\Findings\Ingestion\ScanRunner;
use App\Audit\Findings\ScanStatus;
use App\Models\Audit\Project;
use App\Models\Audit\Scan;

/**
 * Application-level orchestration for a PERSISTED audit: given a Project
 * already registered with LaraDogs, re-discovers its stack fresh (never
 * trusts the stale profile captured at registration — a project's
 * dependencies/framework version can change between audits, and a Scan
 * must preserve exactly what was observed AT THAT SCAN, not at
 * registration time), then delegates the entire execution+persistence
 * path to the already-existing {@see ScanRunner}/`ScanRecorder`/
 * `FindingIngestor`/`FindingReconciler` pipeline (Phase 3) — this class
 * adds NO persistence logic of its own beyond the two reads below, and
 * never touches `Finding.status` directly (see ADR-0010).
 *
 * Concurrency: refuses to start a second audit for a Project that already
 * has a `Running` Scan (see {@see RunProjectAuditOutcome::AlreadyRunning}).
 * This is a deliberately small, portable, ADVISORY guard — a plain query
 * against existing data, not a distributed lock (no Redis/queue
 * infrastructure introduced). It cannot prevent a true race between two
 * processes calling `run()` within the same instant (both could observe
 * "no Running scan" before either creates one) — that residual race is
 * bounded by the SAME database-level protection that already protects
 * concurrent Finding ingestion: `findings_project_fingerprint_unique`
 * (see {@see FindingIngestor}). A crashed/interrupted
 * process can also leave a Scan stuck in `Running` forever — closed by
 * {@see StaleScanReclaimer} (Phase 7): a `Running` scan older than
 * `config('laradogs.projects.stale_scan_threshold_seconds')` is treated
 * as abandoned and reclaimed (marked `Failed`, no Finding touched) before
 * the guard below runs, so a stuck scan no longer blocks this project
 * forever. This closes the specific gap that made unattended (queued/
 * scheduled) triggering unsafe — manually invoking an audit was already
 * safe, since a human could notice and intervene.
 */
final readonly class RunProjectAudit
{
    public function __construct(
        private ProjectDiscovery $discovery,
        private ScanRunner $scanRunner,
        private StaleScanReclaimer $staleScanReclaimer = new StaleScanReclaimer,
    ) {}

    public function run(Project $project): RunProjectAuditResult
    {
        $this->staleScanReclaimer->reclaimIfStale($project);

        $runningScan = Scan::query()
            ->where('project_id', $project->id)
            ->where('status', ScanStatus::Running)
            ->first();

        if ($runningScan !== null) {
            return RunProjectAuditResult::alreadyRunning($runningScan);
        }

        $discoveryResult = $this->discovery->discover($project->path);

        if ($discoveryResult->profile === null) {
            return RunProjectAuditResult::pathUnavailable($discoveryResult);
        }

        $context = new AuditContext(
            runId: (string) str()->uuid(),
            projectPath: $discoveryResult->path,
            profile: $discoveryResult->profile,
        );

        $scan = $this->scanRunner->run($project, $context);

        return RunProjectAuditResult::completed($scan);
    }
}
