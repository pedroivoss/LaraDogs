# Persisted Projects & Scan History

**Status: Implemented (Phase 3.2).** Adds the application/orchestration layer on top
of the already-complete Finding domain (`Project`/`Scan`/
`ScanAnalyzerExecution`/`Finding`/`FindingOccurrence`/
`FindingStatusHistory`, `ScanRunner`/`ScanRecorder`/`FindingIngestor`/
`FindingReconciler` — see
[`findings-lifecycle.md`](findings-lifecycle.md)): registering a project,
running repeated audits against it, and querying its scan/finding
history. **No dashboard, no MCP server, no Git integration, and no
quality gates exist yet** — this is the CLI-adapter and query-service
foundation those will consume.

> **Phase classification: Phase 3.2 — Persistent Project Audit Workflow.**
> A sub-phase of official Phase 3 (Finding Domain + Persistence), same
> convention as Phase 3.1 (Safe Finding Resolution Coverage) and Phase
> 4.1/4.2 — see [`../roadmap/phases.md`](../roadmap/phases.md) and
> [`../roadmap/roadmap.md`](../roadmap/roadmap.md). This is the
> application-level glue (registration, orchestration, queries) Phase 3's
> persistence layer was always missing — **not** the official roadmap's
> own **Phase 7 (Dashboard)**, which remains not started and is unrelated
> to this work.

## What already existed vs. what this adds

The `projects`/`scans`/`scan_analyzer_executions`/`findings`/
`finding_occurrences`/`finding_status_histories` schema, the `Project`/
`Scan`/... Eloquent models, and the full `ScanRunner` → `ScanRecorder` →
`FindingIngestor` → `FindingReconciler` → `FindingLifecycleService`
pipeline were **already built and tested** (Phase 3). Nothing in that
pipeline was rebuilt or duplicated here. What was actually missing, and
what this work adds:

- A way to actually create a `Project` row from a real path
  (`App\Audit\Projects\RegisterProject`) — no service or CLI command did
  this before.
- A way to run a **persisted** audit against an already-registered
  project (`App\Audit\Projects\RunProjectAudit`) — `ScanRunner` already
  existed, but nothing outside tests called it.
- A small query/service layer over already-persisted data
  (`App\Audit\Projects\Query\*`) — project list, scan history, current
  findings with filters, project summary.
- Three CLI commands wiring the above into the existing
  `laradogs:`-prefixed command family.
- One migration: a unique index on `projects.path` (didn't exist before).
- One relation added to the existing `Project` model (`latestScan()`).

## Project identity

A `Project` is something auditable **registered** with LaraDogs — not the
source repository itself, and not a snapshot of its stack (that's what a
`Scan`'s own `project_profile` is, per scan — see
[Registration profile vs. scan-time profile](#registration-profile-vs-scan-time-profile)
below). Fields: a public ULID (`public_id`, the only identifier any CLI/
future adapter should accept — never the internal numeric `id`), `name`,
`path`, timestamps. No active/inactive flag exists — deliberately: nothing
in this phase needs one, and adding one unused would be schema expansion
without a consumer.

## Registering a project

```bash
php artisan laradogs:project:add /path/to/your/laravel/project
php artisan laradogs:project:add /path/to/your/laravel/project --name="Display Name"
php artisan laradogs:project:add /path/to/your/laravel/project --json
```

`path` is validated the same way `laradogs:inspect`/`laradogs:audit`
already validate a path — through `ProjectDiscovery` itself (`realpath()`
→ must be a directory → must be readable). **Nothing from the target is
ever executed** during registration; only Discovery's own static
inspection runs.

### Duplicate registration semantics

Registering the **same resolved path** twice is **idempotent, not an
error**: the second call returns the existing `Project` row (exit code 0,
"Already registered"), never creates a duplicate. This was a deliberate
choice among the two documented alternatives (return existing vs. fail
with a diagnostic) — idempotent registration means a script that runs
`laradogs:project:add` unconditionally on every deploy never needs special
"did this already exist?" handling.

The path is always **realpath-resolved** before comparison/storage (the
exact same resolution `ProjectDiscovery` already performs), so two
different symlinks — or a relative vs. absolute spelling — pointing at the
same real directory are correctly recognized as the same project. A
`projects.path` unique database index (not just an application-level
check) is the actual correctness guarantee under concurrent registration,
mirroring the same pattern `FindingIngestor` already uses for fingerprint
uniqueness (see [`findings-lifecycle.md`](findings-lifecycle.md)):
`RegisterProject` checks first to avoid the exception in the common case,
but catches a unique-constraint violation and returns the winning row
rather than surfacing a spurious error.

A project registered under a `--name` is **not** renamed by a later
registration call with a different `--name` — idempotent identity, not an
update operation.

## Path availability, and a project moving/disappearing

A registered project's `path` can become unavailable later (moved,
deleted, permissions changed) — this is expected and handled, not an edge
case papered over. Registration never assumes the path stays valid
forever:

- **At registration time**, an invalid path fails cleanly (exit 1, no
  `Project` row created, no stack trace) with the same
  `DiscoveryStatus`-derived message `laradogs:inspect`/`laradogs:audit`
  already produce (`Path not found` / `Not a directory` / `Path is not
readable`).
- **At audit time** (see below), the path is **re-discovered fresh** —
  never trusted from registration or from any previous scan. If it's no
  longer valid, `laradogs:project:audit` fails the same way (exit 1, clear
  message, **no new `Scan` row created**) — the project's prior scan
  history is left completely untouched.

## Registration profile vs. scan-time profile

Registration itself does **not** persist a stack profile anywhere on the
`Project` row — there is no such column. Every `Scan` carries its **own**
`project_profile` JSON snapshot, captured fresh at that scan's start via a
brand-new `ProjectDiscovery::discover()` call (see `RunProjectAudit`'s own
docblock). A project's dependencies/framework version can change between
audits; re-running Discovery every time, rather than caching a snapshot
from registration, is what makes each `Scan` self-describing independent
of the project's current state (a requirement already established by
Phase 3 — see [`findings-lifecycle.md`](findings-lifecycle.md)).

If you want to inspect a registered project's **current** stack without
running a full audit, `laradogs:inspect {path}` (the existing, unchanged
ad-hoc command) already does exactly that — pass the project's own `path`
column value.

## Running a persisted audit

```bash
php artisan laradogs:project:audit {project-public-id}
php artisan laradogs:project:audit {project-public-id} --json
```

`{project-public-id}` is a project's `public_id` (shown by
`laradogs:project:list`) — never the internal numeric id, and no ambiguous
name-based lookup exists (a project's `name` is not unique, so name
lookup would be inherently ambiguous — public ID is the only supported
identifier).

This delegates the entire execution+persistence path to the
already-existing `ScanRunner`/`ScanRecorder`/`FindingIngestor`/
`FindingReconciler` pipeline — `RunProjectAudit` adds no persistence logic
of its own beyond re-discovering the profile and checking for a
conflicting in-progress scan (below). **A "completed" scan status does
NOT mean the project is clean** — it means the scan itself finished;
inspect the scan's own analyzer executions and findings (exactly the same
distinction `laradogs:audit`'s own docs already make).

### Concurrency

`laradogs:project:audit` refuses to start a second audit for a project
that already has a `Scan` with `status = running`:

```
Another audit for this project is already running (scan 01ABC..., started 2 minutes ago).
```

This is a deliberately small, **portable, advisory** guard — a plain
query against existing data, not distributed locking (no Redis/queue
infrastructure was introduced). It cannot prevent a true race between two
processes calling the audit at the exact same instant (both could observe
"no running scan" before either creates one); that residual race is
bounded by the same database-level protection that already protects
concurrent Finding ingestion — `findings_project_fingerprint_unique` (see
`FindingIngestor`). A crashed process can also leave a `Scan` stuck in
`running` forever — there is no staleness/timeout cleanup for that yet
(see [Known limitations](#known-limitations)).

### What gets persisted, every audit

Every audit creates one new, **immutable** `Scan` row — never overwritten,
never reused for a later run:

- The `Scan` itself: status, `started_at`/`finished_at`, `duration_ms`,
  the `project_profile` snapshot, `environment`, `findings_summary`,
  `laradogs_version`. `source_revision` stays `null` — no Git integration
  exists yet.
- One `ScanAnalyzerExecution` per analyzer that ran: id/name/category/
  status/summary/diagnostics/**coverage**/duration. Coverage (`Full` /
  `Explicit` / `Unknown`) is always preserved, including for a timed-out
  or failed analyzer (which is recorded with `Unknown` coverage, never
  silently dropped).
- Every observed `FindingCandidate` is ingested through the existing
  `Finding`/`FindingOccurrence` domain — **no second "dashboard finding"
  model was created.** A `Finding` is the stable, cross-scan logical
  identity; a `FindingOccurrence` is the evidence for ONE scan.

### Reconciliation / auto-resolution (unchanged, safety-critical)

`RunProjectAudit` never touches `Finding.status` directly and never calls
`FindingReconciler` with custom logic — it only ever gets there through
`ScanRecorder::completeScan()`, exactly as before Phase 3.1. The existing,
unmodified safety rule: a finding is only auto-resolved when its own
analyzer `Passed` this scan **and** that execution's `AnalyzerCoverage`
actually verifies the finding's `rule_id` — never from `Passed` alone.

Concretely, for the analyzers that exist today:

- **Semgrep** declares `Explicit` coverage (the rule IDs it actually ran)
  — a finding whose rule is still verified, and no longer reported, DOES
  auto-resolve.
- **Composer Audit / npm audit** declare `Unknown` coverage — their
  findings **never** auto-resolve just because a later scan doesn't
  report them. Absence is not evidence of a fix without explicit
  per-rule/per-advisory coverage, which neither tool provides today.

A resolved finding that reappears in a later scan is automatically
**reopened** (not re-created as a new row) — this is the regression path;
`finding_status_histories` records every transition
(`open → resolved → open`, etc.), and `REGRESSED` is deliberately not a
status a finding can sit in — it's inferable from that history (see
[`findings-lifecycle.md`](findings-lifecycle.md)).

**Suppressed statuses are never touched automatically.** A finding marked
`accepted_risk`/`false_positive`/`ignored` (only ever set through
`FindingLifecycleService`, with a required reason) survives both
re-detection (does not reopen) and a clean re-scan (does not resolve) —
this is a deliberate human decision that persists across every future
audit until a human changes it again.

## Listing projects

```bash
php artisan laradogs:project:list
php artisan laradogs:project:list --json
```

Backed by `App\Audit\Projects\Query\ProjectListQuery` — one query for the
projects, one portable correlated-subquery join (Eloquent's
`latestOfMany()`, no vendor-specific window function) for each project's
latest scan, one aggregate query for open finding counts. No N+1
regardless of how many projects are registered.

## Query services (for future Dashboard/MCP adapters)

None of these have a CLI surface of their own yet beyond what
`laradogs:project:list`/`laradogs:project:audit` already expose — they
exist now so a Dashboard/MCP adapter can be built directly on top of them
later without inventing new query logic at that point.

- `ProjectListQuery::all()` — every project, latest scan, open finding
  count.
- `ScanHistoryQuery::recentFor(Project $project, int $limit = 20)` — most
  recent scans, newest first (tie-broken by `id` — `started_at` is only
  second-precision, so two scans in the same second still sort
  deterministically).
- `ScanHistoryQuery::detail(string $publicScanId)` — one scan with its
  analyzer executions.
- `CurrentFindingsQuery::forProject(Project $project, ?FindingFilters $filters = null)`
  — a project's current findings (i.e. its `Finding` rows — no separate
  "current" concept exists; `FindingOccurrence` is the per-scan evidence
  history). No implicit status filter — resolved findings ARE included
  unless the caller filters them out.
- `CurrentFindingsQuery::forScan(Scan $scan)` — findings actually observed
  IN one specific scan (distinct from a project's current findings, which
  reflect the latest known state regardless of which scan last touched
  them).
- `ProjectSummaryQuery::forProject(Project $project)` — total findings,
  open findings, open-findings breakdown by severity/category, the last
  scan's per-analyzer statuses, the last scan itself. **No health score**
  — no formula for one has been specified; inventing one here would be
  scope creep.

`FindingFilters` supports `status`/`severity`/`category`/`analyzerId`/
`ruleId` — a plain, small filter DTO, not a UI. No filtering UI exists
yet; this is the backend boundary a future one will call into.

## CLI reference

| Command                            | Persists? | Purpose                                                  |
| ---------------------------------- | --------- | -------------------------------------------------------- |
| `laradogs:inspect {path}`          | No        | Ad-hoc stack detection against any directory. Unchanged. |
| `laradogs:audit {path}`            | No        | Ad-hoc one-off audit against any directory. Unchanged.   |
| `laradogs:project:add {path}`      | Yes       | Register a directory as a Project.                       |
| `laradogs:project:list`            | —         | List registered Projects.                                |
| `laradogs:project:audit {project}` | Yes       | Run a persisted audit for an already-registered Project. |

Every new command supports `--json` and returns a non-zero exit code with
a plain, single-line diagnostic (never a stack trace) for expected user
errors — unknown project ID, invalid/unavailable path, a conflicting
running scan.

## Path privacy

A registered project's `path` is a local absolute filesystem path, which
may contain a username or other host-specific detail — this is expected
and, for a self-hosted single-tenant tool, acceptable to store: LaraDogs'
own database is not designed to be shared beyond the operator who runs it.
Current policy:

- Absolute paths ARE stored in `projects.path`/every `Scan`'s
  `project_profile` — this is necessary for the audit to actually run
  again later.
- Absolute paths are **not** hardcoded into documentation or tests — every
  fixture path in this codebase is built from `dirname(__DIR__, N)` or
  similar, never a real developer's home directory (see
  [Tests](#tests)/[No project-specific code](#no-project-specific-code)
  below).
- No new logging was introduced that writes a project's path anywhere
  beyond what `laradogs:project:list`/`laradogs:project:audit`'s own
  output (human or `--json`) already, deliberately, includes for the
  operator running them.

## Docker

Inside a container, a project's registered `path` is the **container**
path, not the host path — the same distinction
[`../testing/manual-audit.md`](../testing/manual-audit.md)'s Docker
section already documents for `laradogs:audit`. Mount the target
read-only (`:ro`) the same way, and register/audit using the mounted
container path:

```bash
docker run --rm \
  -e APP_KEY="$(php artisan key:generate --show)" \
  -v "/path/to/your/laravel/project:/targets/project:ro" \
  laradogs-app:latest \
  php artisan laradogs:project:add /targets/project
```

No automatic host-path mounting exists, and none was added — the operator
chooses what to mount, same as before. No Docker socket access was added.

## Known limitations

- **A crashed/interrupted process can leave a `Scan` stuck in `running`
  status indefinitely** — no staleness detection/cleanup exists yet (see
  [Concurrency](#concurrency)). A stuck `running` scan blocks new audits
  for that project until manually corrected in the database. **This is
  real operational debt, not a cosmetic gap: it must be resolved before
  any unattended/automated triggering of audits** — CI automation,
  scheduled (cron) scans, Git-webhook-triggered scans, or any other
  unattended operation mode. Manually running `laradogs:project:audit`
  yourself, where a human notices and can intervene if something hangs,
  is safe today; an automated scheduler blindly retrying on a schedule is
  not, until this is addressed.
- **The `add_unique_constraint_to_projects_path` migration will fail
  loudly (not silently) on an existing installation whose `projects`
  table already contains duplicate `path` values** — the database rejects
  `ADD UNIQUE` in that case, aborting the migration rather than corrupting
  data. No automatic deduplication migration is provided (deciding which
  duplicate row is canonical, and what happens to its scans/findings, is
  an operational decision only a human running that installation can
  safely make). In practice this should be rare: no shipped code path has
  ever created a `Project` row without going through `RegisterProject`,
  which already enforces uniqueness at the application level — but a
  pre-existing installation seeded some other way could hit this.
- No way to rename/deactivate/delete a registered project yet — only
  `laradogs:project:add`/`laradogs:project:list`/`laradogs:project:audit`
  exist.
- No Git integration — `source_revision` stays `null` on every scan.
- No quality gates/pass-fail policy — a scan's own status is never a
  pass/fail verdict on the project.
- No filtering UI, no Dashboard, no MCP server — the query/service layer
  above exists specifically so those can be built without new query logic
  when they arrive.

## No project-specific code

None of the above is tailored to any specific real project — including
the one used for Phase 6/6.1's own real-world rule validation. Every test
in this area uses synthetic fixtures under `tests/Fixtures/discovery/`
and temporary directories, never a real developer's project. See
[Tests](#tests).

## Tests

`tests/Feature/Audit/Projects/` (`RegisterProjectTest`,
`RunProjectAuditTest`, `Query/QueryServicesTest`) and
`tests/Feature/Console/` (`ProjectAddCommandTest`, `ProjectListCommandTest`,
`ProjectAuditCommandTest`) — all built on the same fixture-based,
real-Discovery-plus-real-Engine-plus-real-persistence convention already
established by `ScanRecorderIntegrationTest` (Phase 3), with fakes only at
the process-execution boundary (synthetic `Analyzer` implementations from
`tests/Support/Engine/Analyzers/`, never a real Composer/npm/Semgrep
process). No real network access, no real project outside this
repository's own fixtures.
