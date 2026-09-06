# Data Flow

## Today: a standard Inertia request

This is the only data flow that exists in the codebase right now.

```
Browser
  │  GET /dashboard
  ▼
routes/web.php ──▶ Controller ──▶ Inertia::render('dashboard')
  │
  ▼
HandleInertiaRequests middleware (shares auth user, flash, appearance)
  │
  ▼
React page component (resources/js/pages/dashboard.tsx)
  │  renders using props sent from the controller
  ▼
Browser (client-side navigation from then on via Inertia)
```

Wayfinder-generated helpers (`resources/js/actions`, `resources/js/routes`)
let the React side call `login.store()`-style functions instead of
hand-written URL strings; they're regenerated from `routes/*.php` at build
time, not hand-maintained.

## Implemented: Project Discovery (Phase 1)

```
CLI (`php artisan laradogs:inspect {path}`)
  │
  ▼
App\Console\Commands\InspectProjectCommand (thin adapter, no logic)
  │
  ▼
App\Audit\Discovery\ProjectDiscovery::discover()
  │  path validation (exists / is a directory / readable) → ProjectFilesystem
  ▼
ComposerManifest / NpmManifest (safe JSON parse of composer.json/lock,
  │                              package.json — never executed)
  ▼
Inspectors (Composer, Laravel, Frontend, Testing, Infrastructure, Database)
  │  each reads only static evidence via ProjectFilesystem
  ▼
ProfileBuilder → ProjectProfile
  │
  ▼
DiscoveryResult ──▶ CLI human-readable output / `--json`
```

No `Finding` is produced and no scanner runs — see
[`../auditing/project-discovery.md`](../auditing/project-discovery.md) and
[ADR-0008](decisions/ADR-0008-static-project-discovery.md).

## Implemented: Audit Engine foundation (Phase 2)

```
AuditContext (runId, projectPath, ProjectProfile from Discovery, settings)
  │
  ▼
AuditEngine::plan()
  │  for each registered Analyzer: applicability(profile) → (if applicable)
  │  availability(context) — run() is never called here
  ▼
AuditPlan (ordered AuditPlanItem list — Planned / NotApplicable / Unavailable)
  │
  ▼
AuditEngine::execute(plan, context)
  │  Planned items: run() inside try/catch, exceptions normalized to Failed;
  │  NotApplicable/Unavailable items: carried over, run() never called;
  │  continue_on_failure=false: remaining Planned items after a failure → Skipped
  ▼
AuditRunResult (runId, plan, one AnalyzerExecution per item, timing)
```

No real analyzer exists yet (only synthetic ones under
`tests/Support/Engine/Analyzers/`), no `Finding` is produced, and nothing
is persisted — see
[`../auditing/audit-engine.md`](../auditing/audit-engine.md) and
[ADR-0009](decisions/ADR-0009-audit-engine-foundation.md).

## Implemented: Finding ingestion and lifecycle (Phase 3)

```
ScanRecorder::startScan(project, profile)
  │  creates a Scan row (status=running) with a project_profile snapshot
  ▼
[ real AuditEngine::run() from Phase 2, synthetic FindingCandidates per
  analyzer — no real analyzer produces these yet ]
  ▼
ScanRecorder::completeScan(scan, runResult, candidatesByAnalyzer)
  │
  ├─▶ one ScanAnalyzerExecution persisted per AnalyzerExecution
  │
  ├─▶ FindingIngestor::ingest() per candidate
  │     fingerprint → find-or-create Finding (locked, project-scoped)
  │     → create/update FindingOccurrence for this scan
  │     → reopen if previously Resolved; suppressed statuses untouched
  │
  ├─▶ FindingReconciler::reconcile()
  │     auto-resolves OPEN/CONFIRMED findings whose analyzer completed
  │     Passed this scan and were not re-observed — nothing else
  │
  ▼
Scan marked Completed (or Failed, on exception) with findings_summary
```

No real analyzer produces a `FindingCandidate` yet — everything above is
exercised with synthetic candidates in tests. See
[`../auditing/findings-lifecycle.md`](../auditing/findings-lifecycle.md)
and [ADR-0010](decisions/ADR-0010-finding-identity-occurrences-and-lifecycle.md).

## Planned: a full audit run (Phase 4+)

Not implemented. Recorded here so the eventual implementation has a target
shape consistent with [ADR-0002](decisions/ADR-0002-application-architecture.md)
and [ADR-0004](decisions/ADR-0004-scanner-execution-strategy.md). Phases 1–3
(above) already deliver stack detection, orchestration, and persistence/
lifecycle; what's missing is real analyzers that actually produce
`FindingCandidate`s from real scanner output, and real process execution.

```
CLI / MCP tool / Dashboard "Run Scan" action
  │
  ▼
Audit Core: stack detection (Project Discovery — implemented)
  ▼
Audit Core: analyzer selection (Audit Engine planning — implemented,
  │  foundation only: no real analyzer registered yet)
  ▼
Audit Core: analyzer execution
  │  (real analyzers, isolated subprocess via ProcessRunner — contract
  │   exists, Phase 4 implements it; timeout, resource limits, non-root —
  │   see ADR-0004/ADR-0009)
  ▼
Audit Core: normalization
  │  (raw scanner output → FindingCandidate — implemented shape, Phase 4
  │   supplies the first real producer of it)
  ▼
Audit Core: correlation + deduplication
  │  (same underlying issue reported by multiple scanners → one Finding —
  │   fingerprinting/ingestion implemented, Phase 3)
  ▼
Audit Core: Laravel-aware rule pass
  │  (framework-specific heuristics layered on top of generic scanner output)
  ▼
Persistence: Finding ingestion + lifecycle (implemented, Phase 3 — see above)
  ▼
Findings ──▶ CLI output / MCP tool response / Dashboard views / CI gate
```

The first three "Audit Core" boxes' outputs (stack detection, analyzer
selection/planning, and Finding persistence/lifecycle) currently
correspond to directories in this repository (`app/Audit/Discovery/`,
`app/Audit/Engine/`, `app/Audit/Findings/`) — only real analyzer
execution/normalization (a real producer of `FindingCandidate`) and
Laravel-aware rules do not exist yet. See [`components.md`](components.md)
for what exists today, and [`../roadmap/phases.md`](../roadmap/phases.md)
for when each stage is expected to land.
