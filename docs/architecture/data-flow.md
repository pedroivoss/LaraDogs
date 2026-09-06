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

## Planned: a full audit run (Phase 2+)

Not implemented. Recorded here so the eventual implementation has a target
shape consistent with [ADR-0002](decisions/ADR-0002-application-architecture.md)
and [ADR-0004](decisions/ADR-0004-scanner-execution-strategy.md). Phase 1
(above) already delivers the first step; Phase 2 wraps it in orchestration
and adds everything after it.

```
CLI / MCP tool / Dashboard "Run Scan" action
  │
  ▼
Audit Core: stack detection (Project Discovery — implemented, see above)
  ▼
Audit Core: scanner selection
  │  (which of composer audit / npm audit / PHPStan / Larastan / ESLint /
  │   Semgrep / OSV-Scanner / Trivy / Pest / PHPUnit are applicable and
  │   installed)
  ▼
Audit Core: scanner execution
  │  (isolated subprocess: timeout, resource limits, non-root — see ADR-0004)
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

Only the first "Audit Core" box (stack detection) currently corresponds to
a directory in this repository (`app/Audit/Discovery/`) — the rest (scanner
selection/execution/normalization/correlation/rules, and persistence) does
not exist yet. See [`components.md`](components.md) for what exists today,
and [`../roadmap/phases.md`](../roadmap/phases.md) for when each stage is
expected to land.
