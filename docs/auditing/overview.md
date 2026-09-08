# Auditing: Overview

**Status: Partially implemented.** Step 1 (stack detection) is implemented
— see [`project-discovery.md`](project-discovery.md). Step 2 (analyzer
selection) is implemented, with three real analyzers registered
(`composer-audit`, Phase 4; `npm-audit`, Phase 4.2; `semgrep`, Phase 5 —
deterministically coexisting in the same registry) — see
[`audit-engine.md`](audit-engine.md).
Step 3 (isolated execution) is implemented for all three via the same real
`ProcessRunner` — see
[`../development/process-execution.md`](../development/process-execution.md).
Step 4's normalized shape (`FindingCandidate`) and step 7's
persistence/lifecycle are implemented, and now have three real producers —
see [`findings-lifecycle.md`](findings-lifecycle.md),
[`analyzers/composer-audit.md`](analyzers/composer-audit.md),
[`analyzers/npm-audit.md`](analyzers/npm-audit.md), and
[`analyzers/semgrep.md`](analyzers/semgrep.md). Step 6 (Laravel-aware
rules) now has a first, deliberately minimal foundation — see
[`static-analysis.md`](static-analysis.md) and [`rules.md`](rules.md) for
what exists (a 3-rule proof-of-vertical ruleset) versus what remains
future work (a comprehensive Laravel-aware rule library). Steps 5 and 8
remain planned, agreed during Phase 0/3 so later phases have a shared
target instead of each improvising the shape independently.

## What an audit will do (once fully built)

1. **Detect the target project's stack** (Laravel version, Blade/Livewire/
   Inertia/React/Vue presence, Composer/NPM/Docker/CI configuration) — see
   [`project-discovery.md`](project-discovery.md).
2. **Select applicable scanners** (`composer audit` — implemented, Phase 4;
   `npm audit` — implemented, Phase 4.2; Semgrep — foundation implemented,
   Phase 5, with a small 3-rule bundled ruleset (see
   [`static-analysis.md`](static-analysis.md)) — PHPStan/Larastan, ESLint,
   OSV-Scanner, Trivy, Pest/PHPUnit, ...) — only tools actually
   installed/usable, degrading gracefully otherwise — see
   [`audit-engine.md`](audit-engine.md) for the applicability/availability
   distinction and planning mechanics.
3. Execute each scanner in isolation (timeout, resource limits, controlled
   environment — see
   [ADR-0011](../architecture/decisions/ADR-0011-safe-external-process-execution.md)
   for the real `ProcessRunner` implementation this runs through, and
   [ADR-0004](../architecture/decisions/ADR-0004-scanner-execution-strategy.md)/
   [ADR-0009](../architecture/decisions/ADR-0009-audit-engine-foundation.md)
   for the earlier design decisions it builds on).
4. **Normalize** each scanner's raw output into a
   [`FindingCandidate`](findings-lifecycle.md#ingestion) — implemented,
   with `composer-audit` (Phase 4), `npm-audit` (Phase 4.2), and `semgrep`
   (Phase 5) as real producers.
5. Correlate and deduplicate findings that describe the same underlying
   issue across multiple scanners.
6. Apply Laravel-aware rules layered on top of generic scanner output
   (Eloquent, policies, middleware, mass assignment, queues, CORS,
   Sanctum, etc. — see the product brief for the full list). A first,
   deliberately minimal foundation exists as of Phase 5 (3 bundled Semgrep
   rules proving the vertical — see [`static-analysis.md`](static-analysis.md)
   and [`rules.md`](rules.md)); the comprehensive Laravel-aware rule
   library this step describes does not exist yet.
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
