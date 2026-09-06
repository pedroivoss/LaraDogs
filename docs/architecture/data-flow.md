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

## Planned: a full audit run (Phase 3+)

Not implemented. Recorded here so the eventual implementation has a target
shape consistent with [ADR-0002](decisions/ADR-0002-application-architecture.md)
and [ADR-0004](decisions/ADR-0004-scanner-execution-strategy.md). Phases 1
and 2 (above) already deliver stack detection and the orchestration
foundation; what's missing is real analyzers, `Finding` normalization, and
persistence.

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
  │  (raw scanner output → Finding shape — see ADR-0003)
  ▼
Audit Core: correlation + deduplication
  │  (same underlying issue reported by multiple scanners → one Finding)
  ▼
Audit Core: Laravel-aware rule pass
  │  (framework-specific heuristics layered on top of generic scanner output)
  ▼
Persistence: immutable Scan record + Findings
  │  (fingerprint-based diff against the previous Scan for this project —
  │   see ADR-0005)
  ▼
Findings ──▶ CLI output / MCP tool response / Dashboard views / CI gate
```

The first two "Audit Core" boxes (stack detection, analyzer
selection/planning) currently correspond to directories in this repository
(`app/Audit/Discovery/`, `app/Audit/Engine/`) — analyzer
execution/normalization/correlation/rules, and persistence, do not exist
yet. See [`components.md`](components.md) for what exists today, and
[`../roadmap/phases.md`](../roadmap/phases.md) for when each stage is
expected to land.
