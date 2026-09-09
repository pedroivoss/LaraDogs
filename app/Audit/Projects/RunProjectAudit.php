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
 * process can also leave a Scan stuck in `Running` forever; there is no
 * staleness/timeout cleanup for that yet. Manually invoking an audit,
 * where a human can notice and intervene, is safe today — this MUST be
 * resolved before this class is ever driven by unattended automation (CI,
 * a scheduler/cron, a Git webhook, or any other unattended trigger) — see
 * docs/auditing/projects.md's Known limitations.
 */
final readonly class RunProjectAudit
{
    public function __construct(
        private ProjectDiscovery $discovery,
        private ScanRunner $scanRunner,
    ) {}

    public function run(Project $project): RunProjectAuditResult
    {
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
