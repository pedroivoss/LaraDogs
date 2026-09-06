# Auditing: Overview

**Status: Partially implemented.** Step 1 below (stack detection) is
implemented — see [`project-discovery.md`](project-discovery.md). Steps
2-8 record the target domain model agreed during Phase 0 so that Phase 2
(Audit Engine Foundation) and Phase 3 (Finding Domain + Persistence) have
a shared target instead of each improvising the shape independently; none
of that is implemented yet. Discovery does not run scanners and does not
produce a `Finding` — it only feeds step 2 what it needs to pick scanners.

## What an audit will do (once fully built)

1. **Detect the target project's stack** (Laravel version, Blade/Livewire/
   Inertia/React/Vue presence, Composer/NPM/Docker/CI configuration) — see
   [`project-discovery.md`](project-discovery.md).
2. Select applicable scanners (`composer audit`, `npm audit`, PHPStan/
   Larastan, ESLint, Semgrep, OSV-Scanner, Trivy, Pest/PHPUnit, ...) —
   only tools actually installed/usable, degrading gracefully otherwise.
3. Execute each scanner in isolation (timeout, resource limits, non-root —
   see [ADR-0004](../architecture/decisions/ADR-0004-scanner-execution-strategy.md)).
4. Normalize each scanner's raw output into the common
   [`Finding`](findings.md) shape.
5. Correlate and deduplicate findings that describe the same underlying
   issue across multiple scanners.
6. Apply Laravel-aware rules layered on top of generic scanner output
   (Eloquent, policies, middleware, mass assignment, queues, CORS,
   Sanctum, etc. — see the product brief for the full list; none of these
   rules exist yet).
7. Persist the result as an immutable `Scan` and compare it against the
   project's previous scan (NEW / RESOLVED / UNCHANGED / REGRESSED — see
   [ADR-0005](../architecture/decisions/ADR-0005-persistence-and-deployment-profiles.md)).
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

- [`findings.md`](findings.md) — the `Finding` entity's fields.
- [`severity.md`](severity.md) / [`confidence.md`](confidence.md) — the two
  independent triage axes.
- [`suppressions.md`](suppressions.md) — how a finding can be marked
  resolved/accepted/false-positive without losing history.
