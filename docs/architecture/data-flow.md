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

## Planned: an audit run (Phase 2+)

Not implemented. Recorded here so the eventual implementation has a target
shape consistent with [ADR-0002](decisions/ADR-0002-application-architecture.md)
and [ADR-0004](decisions/ADR-0004-scanner-execution-strategy.md).

```
CLI / MCP tool / Dashboard "Run Scan" action
  │
  ▼
Audit Core: stack detection
  │  (Laravel version, Blade/Livewire/Inertia/React/Vue presence,
  │   Composer/NPM/Docker/CI config present)
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

The "Audit Core" box above does not currently correspond to any directory
in this repository — see [`components.md`](components.md) for what exists
today, and [`../roadmap/phases.md`](../roadmap/phases.md) for when each
stage is expected to land.
