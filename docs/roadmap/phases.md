# Phase Definitions

## Phase 0 — Discovery / Architecture / Bootstrap

**Goal:** a solid, reproducible foundation — not audit features.

Definition of Done (reproduced from the Phase 0 execution brief; see the
Phase 0 report for the filled-in checklist with PASS/FAIL/BLOCKED per
item):

- Environment inspected; current documentation consulted (not assumed
  from training data).
- Initial architectural decisions recorded (ADRs).
- Laravel functional; React/Inertia functional.
- Local environment reproducible without Docker.
- Docker functional (or blockers documented).
- Basic tests pass; frontend build passes.
- Initial documentation exists; README matches real state.
- Architecture, security model, and roadmap documented.
- No secret committed to Git.
- No Phase 1+ feature accidentally implemented.

## Phase 1 — Project Discovery ✅ Complete

Detect a target project's stack — Laravel version, presence of
Blade/Livewire/Inertia/React/Vue/TypeScript, Composer/NPM manifests,
testing tools, Docker/CI configuration, database driver hints — via
static evidence only, never by executing anything from the target. See
[`../auditing/project-discovery.md`](../auditing/project-discovery.md) and
[ADR-0008](../architecture/decisions/ADR-0008-static-project-discovery.md).
This is what Phase 2's engine will consume to decide which scanners apply
to a given project.

Whether a given scanner _binary_ (Semgrep, Trivy, OSV-Scanner, ...) is
actually installed and usable on the **host running LaraDogs** — a
different question from what the target project's manifests declare — is
not part of this phase's `ProjectProfile`; ADR-0004 calls that out as
scanner-availability detection, and it's addressed when Phase 4 actually
integrates those scanners, not guessed at here.

## Phase 2 — Audit Engine Foundation ✅ Complete

The orchestration layer: given a `ProjectProfile`, decide which analyzers
apply (`Applicability`) and are actually runnable on this host
(`Availability`), build an inspectable `AuditPlan`, execute it, and
normalize every outcome (success/failure/exception/timeout/skip) into an
`AuditRunResult`. See
[`../auditing/audit-engine.md`](../auditing/audit-engine.md) and
[ADR-0009](../architecture/decisions/ADR-0009-audit-engine-foundation.md).

**No real scanner integration** (`composer audit`, `npm audit`, PHPStan,
Semgrep, Trivy, OSV-Scanner, ESLint, Pest/PHPUnit-as-scanner) exists yet —
that's Phase 4. This phase validated the orchestration contract against
synthetic analyzers only. No `Finding` model yet — that's Phase 3. A
`ProcessRunner` interface (real subprocess execution, isolated — see
ADR-0004) is recorded now with zero implementation, so Phase 4's first
real analyzer has a stable contract instead of reaching for
`shell_exec()` inline.

## Phase 3 — Finding Domain + Persistence ✅ Complete

Implemented the `Project`/`Scan`/`ScanAnalyzerExecution`/`Finding`/
`FindingOccurrence`/`FindingStatusHistory` schema, the `FindingCandidate`
normalization DTO, a versioned (`v1`) line-number-independent fingerprint,
and the full lifecycle (statuses, auto-resolution safety, regression/
reopen) — see
[`../auditing/findings-lifecycle.md`](../auditing/findings-lifecycle.md)
and [ADR-0010](../architecture/decisions/ADR-0010-finding-identity-occurrences-and-lifecycle.md),
which resolves what ADR-0003/ADR-0005 deliberately left open.

**No real scanner integration exists yet** — everything is exercised with
synthetic `FindingCandidate`s (Phase 4 supplies the first real producer of
one). Migrations use only portable Laravel primitives, per
[ADR-0007](../architecture/decisions/ADR-0007-database-agnostic-persistence.md)
(SQLite/MySQL/MariaDB/PostgreSQL) — no vendor-specific enum types, JSON
operators, generated columns, or partial indexes.

**Sub-phases (both delivered on top of this phase's own schema/pipeline,
each documented as an amendment rather than a new top-level phase — this
document's official phase list stays exactly as numbered below):**

- **Phase 3.1 — Safe Finding Resolution Coverage.** Closed a real gap in
  this phase's original auto-resolution rule: an analyzer merely
  `Passed`-ing said nothing about which specific rules it had actually
  verified, so a finding whose rule was silently disabled/removed could
  be wrongly auto-resolved. Added `AnalyzerCoverage` (`Full`/`Explicit`/
  `Unknown`) to `AnalyzerResult`; `FindingReconciler` now only
  auto-resolves when coverage explicitly verifies the finding's own
  `rule_id` — never from `Passed` alone. See
  [ADR-0010's amendment](../architecture/decisions/ADR-0010-finding-identity-occurrences-and-lifecycle.md#amendment-phase-31-coverage-gated-auto-resolution).
- **Phase 3.2 — Persistent Project Audit Workflow.** Phase 3 above
  already shipped the full `Project`/`Scan`/... persistence schema and
  the `ScanRunner`/`ScanRecorder`/`FindingIngestor`/`FindingReconciler`
  pipeline, but nothing outside tests ever called it — no CLI command
  created a `Project` row or ran a persisted audit. Phase 3.2 added
  exactly that missing application-level glue and nothing else: project
  registration with idempotent duplicate-path semantics
  (`RegisterProject`), persisted-audit orchestration that re-runs
  Discovery fresh every time and delegates entirely to the existing
  `ScanRunner` (`RunProjectAudit`), a project/scan/finding query layer
  (`App\Audit\Projects\Query`), and three new CLI commands
  (`laradogs:project:add`/`list`/`audit`) alongside the existing,
  unchanged `laradogs:inspect`/`laradogs:audit`. No dashboard, no MCP, no
  Git integration, no quality gates — see
  [`../auditing/projects.md`](../auditing/projects.md).

## Phase 4 — Security / Dependency Scanners 🚧 In progress

First real scanner integrations: `composer audit`, `npm audit`,
OSV-Scanner, Trivy, Semgrep. This is also where scanner sandboxing
(ADR-0004) must actually be implemented, not just designed.

**Execution note:** this phase's scope was delivered across several
finer-grained execution sub-phases (tracked informally as "Phase 4",
"Phase 4.1", "Phase 4.2", "Phase 4.2.1", "Phase 5", "Phase 6" in commit
history/ADR notes — a different numbering than this document's own coarse
phase list): `composer audit` (real process execution + Docker),
`npm audit` (+ registry/proxy trust hardening), a Semgrep **foundation**
(a small, 3-rule bundled ruleset proving the Discovery → Engine → Findings
vertical end-to-end — see [`../auditing/static-analysis.md`](../auditing/static-analysis.md)
and [`../auditing/analyzers/semgrep.md`](../auditing/analyzers/semgrep.md)),
and a **first Laravel-aware ruleset** on top of that foundation (9 more
rules — SQL/raw-query, Blade/XSS, command execution, filesystem/path,
open redirect, mass assignment, a second debug helper, a debug-config
check, and one conservative performance hotspot; 12 rules total — see
[`../auditing/rules/security-rules.md`](../auditing/rules/security-rules.md),
[`../auditing/rules/quality-rules.md`](../auditing/rules/quality-rules.md),
and [`../auditing/rules/performance-rules.md`](../auditing/rules/performance-rules.md))
are done. **Still remaining from this phase's original scope:**
OSV-Scanner, Trivy, and — importantly — the COMPREHENSIVE Laravel-aware
Semgrep rule library this 12-rule set is only a deliberately small first
slice of (see [`../auditing/rules.md`](../auditing/rules.md)).

## Phase 5 — Bug / Quality Analysis

PHPStan/Larastan and ESLint integration; first Laravel-aware rules
(mass assignment, raw queries, validation gaps, etc. — see the product
brief's full list).

## Phase 6 — Performance Analysis

Heuristic detection: possible N+1, queries in loops, unbounded
`Model::all()`, missing eager loading, sync-heavy operations that should
be queued.

## Phase 7 — Dashboard

Findings list/detail pages, project/scan navigation, health overview.
Builds on the Inertia/React foundation from Phase 0 but is not itself
Phase 0 work — no dashboard-specific pages exist yet beyond the starter
kit's own settings/auth pages.

## Phase 8 — History / Comparison / Quality Gates

NEW/RESOLVED/UNCHANGED/REGRESSED comparison between scans; quality gates
(fail a build/PR if new CRITICAL findings appear, etc.).

## Phase 9 — MCP

Implement the MCP server described in `docs/integrations/mcp.md` against
a real Finding/Scan implementation.

## Phase 10 — Authentication / MCP Credentials

Harden the auth model beyond the starter-kit default (see
`docs/architecture/security-model.md`'s known limitation on public
registration); implement MCP credential issuance/scoping per ADR-0006.

## Phase 11 — Git Integration / Continuous Monitoring

Watch a repository for changes and trigger scans automatically.

## Phase 12 — CI / GitHub Action

Package LaraDogs as something a GitHub Actions workflow can invoke as a
quality gate on external projects.

## Phase 13 — Hardening / Release

Security review, RBAC, rate limiting, audit logging, CSP/headers, and
whatever else accumulated as a "deferred to hardening" note across prior
phases (see `roadmap.md`'s deferred-items list, which Phase 13 should
treat as a checklist to revisit, not close by default).
