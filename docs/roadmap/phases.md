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

## Phase 7 — Dashboard ✅ Complete

Findings list/detail pages, project/scan navigation. Builds on the
Inertia/React foundation from Phase 0 but is not itself Phase 0 work.
Delivered: an authenticated Projects list, Project Detail (summary,
analyzer status, current findings, scan history), a server-side
filtered/paginated Findings browser, Scan History/Detail (preserving each
scan's own historical snapshot), and Finding Detail with lifecycle status
actions routed through the existing `FindingLifecycleService` — all as a
thin adapter over Phase 3.2's query layer, extended (never duplicated)
only where genuinely needed (real pagination on two existing query
methods; one new cross-project `DashboardSummaryQuery`). Two explicit
design decisions were made and documented rather than guessed at:
Dashboard-triggered audits remain CLI-only this phase (a synchronous
HTTP-held-open scan would itself reproduce the stale-scan problem via
browser/proxy timeouts; a queued job has no monitored worker process
yet), and a `StaleScanReclaimer` was still built as prerequisite
groundwork, genuinely closing Phase 3.2's "a crashed process can leave a
Scan stuck Running" limitation for the CLI-triggered workflow already in
use. **No health score, no charts/trend lines, no project-registration
UI, no MCP, no Git integration, no quality gates** — see
[`../dashboard.md`](../dashboard.md) for the full account, including
known limitations.

### Phase 7.1 — Self-Hosting & Authorization Hardening ✅ Complete

Sub-phases addressing real self-hosted-use gaps identified after Phase 7
shipped, each documented in full under `../self-hosting.md`,
`../dashboard.md`, and `../auditing/`:

- **7.1.1** — Docker Compose profile (`app` + `db`), non-root runtime
  image, MySQL support alongside SQLite.
- **7.1.2** — Dashboard project-registration UI (the "Add Project"
  picker), first-Admin bootstrap, user management basics.
- **7.1.3** — Owner/Admin/User roles (replacing a plain `is_admin` flag),
  Owner privacy (invisible to Admin/User Settings → Users and its
  endpoints), single-Owner invariant.
- **7.1.4** — Asynchronous audit execution: a Dashboard "Run Audit"
  button + queue worker (`database` queue, no Redis), optional per-project
  scheduling (Disabled/Daily/Weekly/Monthly), a real portable concurrency
  mutex (`project_active_scans`), heartbeat-aware stale-scan recovery, and
  two new Docker services (`worker`, `scheduler`). See
  [`../auditing/audit-execution.md`](../auditing/audit-execution.md).

## Phase 8 — Quality Gates & Policy Engine ✅ Complete

_(Originally listed as "History / Comparison / Quality Gates"; retitled
when the phase was scoped. The other phases keep their numbers.)_

A per-project, optional (disabled by default) **policy layer** that judges
a finished scan: **Passed / Failed / Indeterminate**, where Indeterminate
means "not enough trustworthy evidence" (fail closed — absence of evidence
is not evidence of absence). Four stable rule types
(`max-open-findings`, `no-new-severity`, `analyzer-status`,
`analyzer-coverage`), versioned policies with a revision + snapshot stored
on every immutable per-scan result, coverage-aware "new/regressed"
detection against a deterministic baseline (previous Completed scan, using
the existing Finding fingerprint identity), one evaluation path for CLI /
Dashboard / scheduled audits, a Dashboard card + Scan History/Detail
surfaces, and `laradogs:project:gate` with a documented exit-code contract
(`0` passed, `1` failed, `2` indeterminate, `3` error, `4` not evaluated)
for future CI. It is a policy result, **not** a security score, and it
never changes findings or their lifecycle. See
[`../quality-gates/README.md`](../quality-gates/README.md).

**Deliberately not part of this phase** (still open): a dedicated
scan-to-scan comparison **report** (NEW/RESOLVED/UNCHANGED/REGRESSED view),
re-evaluating an existing scan against a new policy, "count accepted
risks" as a policy option, per-analyzer scoping of count rules, and a
Dashboard-home "projects failing" widget.

## Phase 9 — Git & Repository Integration ✅ Complete

LaraDogs became Git-aware **locally and read-only**: every persisted audit
captures an immutable Git source snapshot (full commit SHA, branch or
detached HEAD, dirty flag, commit timestamp/subject, sanitized origin) before
the analyzers run, plus a second capture afterwards. From those two snapshots
a **source-integrity** verdict is derived (Phase 9.1): only a **clean** Git
repository **with a commit** that is identical before and after is verified.
A source that changed during the audit, was **dirty when it began**, has **no
commits**, is a **bare** repository, or whose Git state could **not be read**
(unavailable / refused config) is _unverified_: nothing is auto-resolved from
absence and a Quality Gate can never Pass on absence (a proven violation still
Fails). A genuine non-Git target makes no integrity claim. One canonical
`GitRepositoryInspector` (argv-only, explicit minimal environment,
hostile-config neutralization, repository-controlled `include`/`includeIf`
refused, no hooks, no network, bounded) backs the CLI, Project Detail (current
vs last-audited source) and the audit runner; Scan History and Scan Detail
show revision/provenance. **No** fetch/pull/push, no GitHub/GitLab API, no CI,
no MCP. See [`../git/README.md`](../git/README.md).

## Phase 10 — CI & GitHub Integration ✅ Complete (CI command + Check Run reporting)

`laradogs:ci:audit` is the one machine-oriented entry point: it converges on
the exact same pipeline every other trigger uses (`RegisterProject`
(idempotent) → `RunProjectAudit` → the persisted Scan → its Quality Gate
result) — no second audit pipeline, no CI-specific analyzer orchestration.
Its exit codes reuse Phase 8's contract exactly (`0`/`1`/`2` from the gate,
`3` operational error — including a revision that does not match
`--expected-revision`, verified both before and after the audit — `4` not
evaluated); its JSON `gate` block is byte-identical to
`laradogs:project:gate`'s. Phase 9's source-integrity semantics apply
unchanged (a dirty/inconsistent CI worktree can never yield an absence-based
Pass). GitHub reporting (`--github-report`, `App\Integrations\GitHub`) is an
optional, failure-isolated adapter that creates one Check Run per scan
(outcome-mapped conclusion, least-privilege `checks: write` token,
idempotent per scan) — GitHub consumes results, never defines them. V1's
execution topology is an operator-run, self-hosted LaraDogs instance (the
existing, unchanged Docker Compose stack); no self-contained
GitHub-hosted-runner packaging and no public remote-execution endpoint were
built. See [`../ci/README.md`](../ci/README.md) and
[`../integrations/github.md`](../integrations/github.md).

**Deliberately not part of this phase** (the hosted-repository scope this
entry absorbed from the former "Git Integration / Continuous Monitoring"
title, still open): GitHub API repository browsing, webhooks, managed
(LaraDogs-owned) clones, continuous "watch and re-audit" monitoring, a
repository-committed policy file, PR finding annotations, and validated
GitHub-hosted-runner support.

## Phase 11 — MCP / IDE Integration

Implement the MCP server described in `docs/integrations/mcp.md` against a
real Finding/Scan implementation, and IDE-facing integration. _(Formerly
listed as "Phase 9 — MCP".)_

## Phase 12 — Remediation Workflow

Not yet specified. Not started.

## Not yet placed in the numbering

Left unnumbered on purpose (see the numbering note in
[`roadmap.md`](roadmap.md)) rather than silently renumbered:

- **Authentication / MCP Credentials** _(formerly Phase 10)_ — harden the auth
  model beyond the starter-kit default (see
  `docs/architecture/security-model.md`'s known limitation on public
  registration); implement MCP credential issuance/scoping per ADR-0006.
- **Hardening / Release** _(formerly Phase 13)_ — security review, RBAC, rate
  limiting, audit logging, CSP/headers, and whatever else accumulated as a
  "deferred to hardening" note across prior phases (see `roadmap.md`'s
  deferred-items list, which this phase should treat as a checklist to
  revisit, not close by default).
