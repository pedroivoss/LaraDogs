# Auditing: Overview

**Status: Partially implemented.** Step 1 (stack detection) is implemented
— see [`project-discovery.md`](project-discovery.md). Step 2 (analyzer
selection) is implemented as an orchestration _foundation_ only — see
[`audit-engine.md`](audit-engine.md) — with no real analyzer registered
yet. Step 4's normalized shape (`FindingCandidate`) and step 7's
persistence/lifecycle are also implemented — see
[`findings-lifecycle.md`](findings-lifecycle.md) — but nothing produces a
`FindingCandidate` from real scanner output yet, so no real `Finding` is
ever created outside tests. Steps 3, 5, 6, and 8 remain planned, agreed
during Phase 0/3 so Phase 4+ has a shared target instead of each
improvising the shape independently.

## What an audit will do (once fully built)

1. **Detect the target project's stack** (Laravel version, Blade/Livewire/
   Inertia/React/Vue presence, Composer/NPM/Docker/CI configuration) — see
   [`project-discovery.md`](project-discovery.md).
2. **Select applicable scanners** (`composer audit`, `npm audit`, PHPStan/
   Larastan, ESLint, Semgrep, OSV-Scanner, Trivy, Pest/PHPUnit, ...) —
   only tools actually installed/usable, degrading gracefully otherwise —
   see [`audit-engine.md`](audit-engine.md) for the applicability/
   availability distinction and planning mechanics (foundation
   implemented; no real scanner registered yet).
3. Execute each scanner in isolation (timeout, resource limits, non-root —
   see [ADR-0004](../architecture/decisions/ADR-0004-scanner-execution-strategy.md)
   and [ADR-0009](../architecture/decisions/ADR-0009-audit-engine-foundation.md)
   for the `ProcessRunner` contract this will run through).
4. **Normalize** each scanner's raw output into a
   [`FindingCandidate`](findings-lifecycle.md#ingestion) — implemented
   shape; Phase 4 supplies the first real producer of one.
5. Correlate and deduplicate findings that describe the same underlying
   issue across multiple scanners.
6. Apply Laravel-aware rules layered on top of generic scanner output
   (Eloquent, policies, middleware, mass assignment, queues, CORS,
   Sanctum, etc. — see the product brief for the full list; none of these
   rules exist yet).
7. **Persist** the result as an immutable `Scan` and compare it against
   the project's previous scan — [`findings-lifecycle.md`](findings-lifecycle.md)
   for what's implemented (ingestion, fingerprinting, lifecycle,
   auto-resolution); a dedicated NEW/RESOLVED/UNCHANGED/REGRESSED
   comparison **report** view is still Phase 8, per
   [`suppressions.md`](suppressions.md#comparison-reporting-planned-phase-8).
8. Expose the result via CLI, MCP, and (eventually) the Dashboard.

This whole pipeline runs **locally and deterministically, with zero
required LLM/API-key dependency** — see
[ADR-0002](../architecture/decisions/ADR-0002-application-architecture.md).
AI involvement is an optional layer on top, primarily through MCP, that
consumes findings — it does not produce them.

## Categories

`SECURITY`, `BUG`, `PERFORMANCE`, `DEPENDENCY`, `QUALITY`, `CONFIGURATION`,
`TEST`.

## See also

- [`audit-engine.md`](audit-engine.md) — the Analyzer contract, planning,
  and execution foundation that step 2 above is built on.
- [`findings-lifecycle.md`](findings-lifecycle.md) — identity,
  fingerprinting, ingestion, and auto-resolution safety (implemented).
- [`findings.md`](findings.md) — the `Finding`/`FindingOccurrence` field
  reference.
- [`severity.md`](severity.md) / [`confidence.md`](confidence.md) — the two
  independent triage axes.
- [`suppressions.md`](suppressions.md) — how a finding can be marked
  resolved/accepted/false-positive without losing history.
