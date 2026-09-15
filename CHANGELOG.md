# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
LaraDogs does not yet have versioned releases (pre-1.0, early development)
— entries are grouped by roadmap phase until the first tagged release.

## [Unreleased] — Phase 7.1.4: Audit Execution & Scheduling

Asynchronous audit execution (a Dashboard "Run Audit" button, a queue
worker, and optional per-project scheduling), replacing the CLI-only
trigger Phase 7 shipped with — see
[`docs/auditing/audit-execution.md`](docs/auditing/audit-execution.md).

### Added

- **`ScanStatus::Queued`** — a `Scan` now exists the moment an audit is
  requested (manual, scheduled, or CLI), before discovery even runs, so
  the Dashboard can show "Queued" immediately.
- **`scans.origin`** (`manual`/`scheduled`/`cli`) and
  **`scans.initiated_by_user_id`** (nullable) — provenance for "why did
  this scan happen," without becoming a general audit-log framework.
- **`scans.running_at`/`heartbeat_at`** — a heartbeat touched between
  analyzer stages, used to distinguish a legitimately slow scan from a
  dead worker.
- **`project_active_scans`** — a portable concurrency mutex (primary key
  = `project_id`, one row per project maximum, enforced by the database
  itself on every supported engine) shared by the CLI, the Dashboard, and
  the scheduler — a project can never have two active scans at once.
- **`App\Jobs\RunProjectAuditJob`** — a thin queue job (carries only a
  scan id) on Laravel's portable `database` queue connection (no Redis).
  `$tries = 1` (deliberately no auto-retry of an expensive audit),
  `$timeout = 2000` (above `laradogs.semgrep.timeout_seconds`'s default).
- **`POST /projects/{project}/audits`** — the Dashboard's "Run Audit"
  button (Owner/Admin, throttled, returns immediately).
- **Optional per-project schedule** (`projects.audit_schedule`:
  Disabled/Daily/Weekly/Monthly, `audit_schedule_day_of_week`,
  `audit_schedule_day_of_month`, `next_audit_at`, `last_scheduled_audit_at`)
  — Disabled by default. `App\Audit\Projects\ProjectAuditScheduler` (pure
  date calculation, monthly day-of-month clamped to the target month's
  real last day) and `App\Audit\Projects\DispatchDueProjectAudits`
  (`laradogs:project:dispatch-due-audits`, ticked every minute).
  `PUT /projects/{project}/audit-schedule` (Owner/Admin to change,
  visible to every role).
- **`queued_scan_stale_threshold_seconds`** config (default 120s) —
  separate from the existing `stale_scan_threshold_seconds` (3600s,
  unchanged), since "queued and never picked up" and "running and the
  worker died" are different failure modes.
- **`worker`/`scheduler` Docker services** — same image as `app`;
  `worker` runs `queue:work` (mounts `/projects:ro`), `scheduler` runs
  `schedule:work` (no project mount — it only ever enqueues). Neither
  publishes a host port; neither inherits the `app` image's HTTP
  healthcheck (they don't serve HTTP).
- Inertia's built-in `usePoll()` drives the Dashboard's live Queued/
  Running status while a scan is active — no WebSockets/Reverb/SSE.

### Changed

- `StaleScanReclaimer` now reclaims `Queued` and `Running` scans
  separately (see the new config key above) and prefers `heartbeat_at`
  over `started_at` for `Running` scans when present (falling back for
  scans created before this phase).
- `laradogs:project:audit` keeps its exact prior synchronous CLI
  behavior — internally now goes through `RunProjectAudit::run()`
  (`enqueue()` + `execute()` in one process, `origin = cli`) rather than
  a single-step `startScan()`; its "already running" diagnostic now
  reports the conflicting scan's actual status (`queued` or `running`).
- `docker/entrypoint.sh` gates the migration block behind
  `LARADOGS_SKIP_MIGRATIONS` — `worker`/`scheduler` set this so exactly
  one container (`app`) ever runs migrations.

## [Unreleased] — Phase 7.1.3: Instance Owner & Access Control Hardening

Replaces the Phase 7.1.2 `users.is_admin` boolean with a three-tier
Owner/Admin/User authorization model — see
[`docs/self-hosting.md`](docs/self-hosting.md)'s authorization section.

### Added

- **`users.role`** (portable string column: `owner`/`admin`/`user`, see
  `App\Models\Role`), replacing `is_admin`. **`users.is_active`**
  (default `true`) — deactivation revokes access without deleting the
  account or its historical finding-lifecycle references (`Finding`
  actor identity is a plain string snapshot, never a foreign key, so it
  was never at risk).
- **`App\Policies\UserPolicy`** — the single centralized authorization
  layer for Settings → Users (view/manage/create/createAdmin/activate/
  deactivate/promote/demote), so controllers stay thin.
- **Instance Owner**: exactly one per installation, enforced at the
  application layer. `laradogs:user:create-owner` (fresh install,
  interactive hidden-password prompt or `LARADOGS_ADMIN_*` env for
  automation — no default credential, ever) and
  `laradogs:user:claim-owner {email}` (promotes an existing account —
  the deterministic path for an installation upgrading from Phase 7.1.2
  with more than one prior admin; see the migration's own docblock for
  why that case never auto-selects one).
- **Owner privacy**: the Owner is excluded server-side from every
  Settings → Users response for a non-Owner actor — never merely hidden
  in the UI. An Admin's own listing additionally excludes other Admins.
  Any request naming the Owner's id 404s, identical to a nonexistent id.
- Admin/User account management: Owner may create/manage Admins and
  Users, promote/demote between Admin and User; Admin may create/manage
  Users only. Activate/deactivate revokes access without deleting the
  account.
- `App\Http\Middleware\EnsureUserIsActive` (global) + a custom
  `Fortify::authenticateUsing()` callback — an inactive account can
  neither log in nor keep an already-authenticated session past the next
  request; both fail with the exact same generic message Fortify already
  uses for a wrong password, so an inactive account is never
  distinguishable from a nonexistent/wrong-password one.
- `App\Http\Middleware\EnsureUserIsStaff` (renamed from
  `EnsureUserIsAdmin`) — gates project registration and Settings → Users
  to Owner/Admin.
- Landing/login pages: "administrator" terminology replaced with
  "Instance Owner"; the not-yet-configured state now shows the exact
  `laradogs:user:create-owner` command.

### Changed

- `laradogs:user:create-admin` now requires an Owner to already exist
  (fails cleanly otherwise, pointing at `create-owner`) — a deliberate,
  documented semantics change from Phase 7.1.2, where it created the
  first privileged account at all.
- Project registration authorization unchanged in effect (Owner+Admin
  allowed, User forbidden) but now expressed via the renamed
  `EnsureUserIsStaff` middleware rather than an admin-only check.

### Fixed

- Two real bugs found during this phase's own implementation/testing
  (not the Phase 7.1.2 finding-title bug — see that phase's own
  changelog note elsewhere): `UsersController`'s
  activate/deactivate/promote/demote actions and
  `ClaimOwnerCommand` originally used `$user->update([...])` on `role`/
  `is_active`, both deliberately excluded from `User`'s `#[Fillable]`
  list — silently no-ops instead of raising an error. Fixed to direct
  property assignment (`$user->role = ...; $user->save();`), the same
  pattern already used correctly elsewhere in this codebase.

## [Unreleased] — Phase 7: Dashboard

The canonical, official roadmap Phase 7 — see
[`docs/dashboard.md`](docs/dashboard.md).

### Added

- **The first authenticated Dashboard**: Projects list, Project Detail
  (summary, analyzer status with a coverage-mode tooltip, current
  findings preview, recent scans preview), a server-side filtered
  (status/severity/category/analyzer/rule) and paginated Findings
  browser, Scan History (paginated) and Scan Detail (each scan's own
  immutable historical snapshot — never substituted with current
  project state), and Finding Detail (full evidence, safely-escaped code
  snippets, occurrences, status history) with a lifecycle status-change
  dialog. Every route requires `auth`+`verified` and uses each model's
  public ULID, never the internal numeric id.
- **The Dashboard's one mutation** — finding status transitions — routes
  entirely through the existing, unmodified `FindingLifecycleService`;
  `UpdateFindingStatusRequest` validates shape only, never duplicates
  which statuses require a reason (that rule stays solely in the domain
  service, so a client can't bypass it by skipping its own validation).
  First real caller of `ActorType::User` in shipped code.
- `App\Audit\Projects\Query\CurrentFindingsQuery::paginateForProject()`
  and `ScanHistoryQuery::paginateFor()` — non-breaking paginated siblings
  of the existing unbounded methods, added because the Dashboard's
  findings/scan-history browsers must never load an unbounded list.
- `App\Audit\Projects\Query\DashboardSummaryQuery` (+`DashboardSummary`) —
  the one genuinely new cross-project aggregate (total/open findings,
  critical/high counts, recent scans, analyzer problems), using SQL
  aggregation rather than a full table scan into memory.
- **`App\Audit\Projects\StaleScanReclaimer`** — closes the Phase 3.2 known
  limitation ("a crashed process can leave a Scan stuck `running`
  indefinitely"): a `running` Scan older than
  `LARADOGS_STALE_SCAN_THRESHOLD_SECONDS` (default 3600s) is reclaimed
  (marked `failed`) the next time an audit is attempted for that project.
  No Redis, no new infrastructure; never touches any `Finding`. Wired
  into `RunProjectAudit::run()`, so this also improves the existing
  CLI-triggered workflow, not just a hypothetical future one.
- New reusable frontend components (`resources/js/components/audit/`):
  `SeverityBadge`, `ConfidenceBadge` (deliberately a different visual
  language from severity — never implies "high confidence = high
  severity"), `FindingStatusBadge`, `AnalyzerStatusBadge`,
  `CoverageBadge` (tooltip-explained), `CodeSnippet` (plain JSX text
  interpolation only — never `dangerouslySetInnerHTML`, so finding
  content can never be rendered as HTML), `EmptyState`, `DataPagination`.
  Plus 3 manually-added shadcn primitives (`table`, `pagination`,
  `textarea` — no new Radix dependency needed) and a centralized
  `resources/js/types/audit.ts` mirroring the backend's Severity/
  FindingStatus/ExecutionStatus/CoverageMode/... enums.
- 60 new backend tests: auth-required on every route, real persisted data
  (no fake/hardcoded production data anywhere), no-N+1 check, server-side
  pagination and filtering, historical-snapshot correctness, lifecycle
  transition + required-reason enforcement + suppressed-status
  preservation + invalid-transition rejection, Failed/TimedOut/Unknown-
  coverage representation (never presented as clean), public-ULID
  verification, no-unauthorized-mutation, stale-scan reclaim (+
  non-stale-still-blocks regression).

### Decisions (documented, not implemented — see `docs/dashboard.md`)

- **Dashboard-triggered audits: CLI-only this phase.** A synchronous
  HTTP-request-held-open scan would itself reproduce the stale-scan
  problem via browser/reverse-proxy timeouts (Semgrep alone can take up
  to 1800s); a queued job has no documented, monitored worker process
  yet. Project Detail shows the exact `laradogs:project:audit` command
  instead.
- **Project registration: CLI-only this phase**, same reasoning — no
  arbitrary server-side file browser was built.
- No health score, no charts/trend lines (no historical-comparison
  semantics exist yet to make one meaningful).

## [Unreleased] — Phase 3.2: Persistent Project Audit Workflow

A sub-phase of official Phase 3 (Finding Domain + Persistence), same
convention as Phase 3.1/4.1/4.2 — not
`docs/roadmap/phases.md`'s own official "Phase 7 — Dashboard" (still not
started, unrelated to this work). See
[`docs/auditing/projects.md`](docs/auditing/projects.md).

### Added

- **`App\Audit\Projects\RegisterProject`** — registers a local directory
  as a `Project` LaraDogs can repeatedly audit. Idempotent: registering
  the same realpath-resolved path twice returns the existing project,
  never a duplicate (backed by a new `projects.path` unique index, not
  just an application-level check). Never executes anything from the
  target — only `ProjectDiscovery`'s own static inspection runs.
- **`App\Audit\Projects\RunProjectAudit`** — persisted-audit orchestration
  for an already-registered project. Re-discovers the project's stack
  fresh every audit (never trusts a stale registration-time snapshot),
  then delegates entirely to the already-existing `ScanRunner`/
  `ScanRecorder`/`FindingIngestor`/`FindingReconciler` pipeline (Phase 3) — no persistence logic was duplicated. Refuses to start a second
  audit while one is already `running` for the same project (a small,
  portable, advisory guard — no distributed locking/Redis introduced).
- **`App\Audit\Projects\Query`** — a small query/service layer for a
  future Dashboard/MCP adapter: `ProjectListQuery` (project list with
  latest-scan + open-finding-count, no N+1 via Eloquent's `latestOfMany()`
  correlated-subquery join), `ScanHistoryQuery` (recent scans, one scan's
  detail), `CurrentFindingsQuery` + `FindingFilters` (a project's current
  findings, filterable by status/severity/category/analyzer/rule; findings
  observed in one specific scan), `ProjectSummaryQuery` + `ProjectSummary`
  (totals, open-findings breakdown by severity/category, last scan's
  per-analyzer statuses — deliberately no health score, no formula
  specified for one).
- Three new CLI commands: `laradogs:project:add {path}`,
  `laradogs:project:list`, `laradogs:project:audit {project}` — all
  support `--json`, all return a non-zero exit code with a plain
  diagnostic (never a stack trace) for expected user errors. The existing
  `laradogs:inspect`/`laradogs:audit` (ad-hoc, never persist) are
  unchanged.
- `Project::latestScan()` relation (`hasOne(...)->latestOfMany('started_at')`)
  and a new migration adding a unique index on `projects.path`.
- `DiscoveryStatus::describe(string $path): string` — the
  path-not-found/not-a-directory/not-readable message mapping, extracted
  from three now-identical private methods (`AuditCommand`,
  `InspectProjectCommand`, and the two new project commands) into one
  place.
- `docs/auditing/projects.md` — registration/duplicate semantics, path
  availability and failure behavior, registration vs. scan-time profile,
  concurrency behavior, the query layer, CLI reference, path-privacy
  policy, Docker workflow, known limitations.
- 37 new tests: project registration (valid/duplicate/invalid path/
  symlink normalization/distinct projects), persisted-audit orchestration
  end-to-end (Scan/executions/findings/occurrences/profile-snapshot
  persistence, multi-scan history, concurrent-audit refusal, disappeared
  path, analyzer timeout/failure), the full Phase 3.1 reconciliation
  matrix exercised through the PERSISTED workflow specifically (verified
  auto-resolve, Unknown-coverage non-resolution, regression/reopen,
  suppressed-status preservation), the query layer (list/history/
  filtering/summary), and CLI human+JSON output for all three new
  commands. All against synthetic fixtures under `tests/Fixtures/
discovery/` — no real project outside this repository's own fixtures.

### Fixed

- `ScanHistoryQuery::recentFor()` orders by `started_at DESC, id DESC` —
  caught during this work's own test-writing: two scans started within
  the same second (a real possibility; `started_at` is only
  second-precision, portable across SQLite/MySQL/MariaDB/PostgreSQL)
  otherwise sorted nondeterministically. A genuine, if latent, ordering
  correctness fix, not merely a test workaround.

## [Unreleased] — Phase 6: First Laravel-Aware Ruleset

### Added

- **9 new Semgrep rules** on top of Phase 5's 3 proof-of-vertical rules
  (12 total; `SemgrepRuleCatalog::RULESET_VERSION` bumped `2026.09.1` →
  `2026.09.2`), chosen for signal/noise ratio over count: `laradogs.security.sql.tainted-raw-query`,
  `laradogs.security.blade.raw-output-tainted`,
  `laradogs.security.command.tainted-exec`,
  `laradogs.security.filesystem.tainted-path`,
  `laradogs.security.redirect.tainted-open-redirect`,
  `laradogs.security.mass-assignment.request-all`,
  `laradogs.quality.debug.ray-call`,
  `laradogs.configuration.debug.app-debug-default-true`,
  `laradogs.performance.eloquent.unbounded-all`. See
  `docs/auditing/rules/security-rules.md`,
  `docs/auditing/rules/quality-rules.md`, and
  `docs/auditing/rules/performance-rules.md` for what each detects,
  severity/confidence rationale, false-positive analysis, and remediation.
- **Semgrep taint-mode rules** (SQL/command/filesystem/redirect) — the
  first rules in the bundled ruleset to use real source→sink dataflow
  propagation (assignment, string concatenation, string interpolation),
  verified to be part of the OSS engine, not Pro-only.
- `metadata.remediation` — a new, Semgrep-native YAML `metadata:` field
  (mirroring the existing `cwe`/`references` passthrough pattern exactly)
  read into `FindingCandidate::$recommendation`. Every bundled rule
  declares one.
- `AuditCommand` (`laradogs:audit`) now normalizes and prints each
  analyzer's `FindingCandidate`s — rule id, severity, category,
  confidence, file:line, and message for human output; a richer
  `findings` JSON array (also including `recommendation`/`cwe`/
  `references`) for `--json`. Previously the CLI only ever printed an
  analyzer's own summary/diagnostic count, never the findings themselves.
- `docs/testing/manual-audit.md` — the first-real-project manual test
  guide: local/Docker commands (all verified against a real project before
  documenting), expected output, known limitations, how to report a false
  positive, and the no-target-mutation guarantee.
- A "Try LaraDogs" section in the top-level `README.md`.
- 33 new tests, including a 10-case "rule quality gate" opt-in suite
  against the real `semgrep` binary
  (`SemgrepLaravelRulesRealBinaryTest.php`) proving every new rule's
  positive fixtures ARE flagged and every negative/safe fixture is NOT,
  a `AuditCommandTest.php` for the CLI findings rendering, and an
  additional Finding-lifecycle proof (verified resolution + regression)
  using a Phase 6 rule specifically, not just the original 3.

### Fixed

- **A real, empirically-caught false-positive source**: Semgrep's PHP
  matcher treats `->` (method call) and `::` (static call) as
  interchangeable when the receiver is a metavariable. An unrestricted
  `$REQ->get(...)` taint-source pattern was confirmed to also match
  unrelated static calls sharing the same method name (`Storage::get(...)`,
  `Cache::get(...)`, `Model::query()`), producing a false-positive Finding
  on constant, non-tainted code. Fixed with `metavariable-regex`
  restrictions on every affected source/sink pattern, verified to
  eliminate the false positive while preserving every genuine positive
  case. See `docs/auditing/rules/security-rules.md`'s own cross-cutting
  note on this issue.
- The same class of issue in `laradogs.performance.eloquent.unbounded-all`
  (`$MODEL::all()` was also matching `$request->all()`), fixed the same
  way (`metavariable-regex: ^[A-Z]` on the receiver).

### Fixed (real-world validation — 2026-09-08)

- **`laradogs.semgrep.timeout_seconds` default raised from 60s to 1200s
  (20 minutes)**, calibrated from a real first-run validation against a
  real, production Laravel application (908 first-party PHP/Blade files):
  the scan timed out at 60s. Root-caused, not blindly bumped: reproducing
  the exact invocation outside any external timeout showed Semgrep takes
  ~0.86 seconds of its own internal per-file overhead for EACH explicitly-listed
  target file (confirmed to scale linearly, essentially independent of
  rule count — a 3-rule subset and the full 12-rule catalog both took
  ~13 minutes against the same 908 files). A directory-based scan is
  ~200x faster but was verified, live, to let the target's own
  `.semgrepignore` silently hide 264 of 908 real files — re-confirming
  exactly the gap Phase 5/ADR-0012 already closed — so it was rejected
  despite the speed. See `docs/auditing/analyzers/semgrep.md#performance`
  for the full investigation, including a further, not-yet-adopted
  finding: Semgrep has its own built-in default ignore patterns that
  silently excluded ~105 files even in a fresh directory with no
  `.semgrepignore` at all.
- `SemgrepAnalyzer`'s timeout message is now actionable: it names
  `LARADOGS_SEMGREP_TIMEOUT_SECONDS` explicitly and states that coverage
  stays Unknown — previously it only said "timed out after Ns."
- Retested against the same real project after the fix: the scan
  completed successfully (`[passed]`, 32 findings, Explicit coverage,
  ~828s, well inside the new 1200s ceiling).

### Fixed (Phase 6.1 — rule precision refinement, 2026-09-08)

A read-only triage of all 32 findings from the real-world scan above
against the real source found **zero true positives**: all 15
`laradogs.security.filesystem.tainted-path` and all 12
`laradogs.security.sql.tainted-raw-query` findings were false positives (4
of the SQL ones mechanical duplicates), and 2 of 5
`laradogs.performance.eloquent.unbounded-all` findings were also false
positives (the other 3 were legitimate review items, exactly as that
rule's Info/Low design intends). Refined matcher precision on these exact
3 rules only — no rule added, removed, or renamed;
`severity`/`confidence`/`category` unchanged.
`SemgrepRuleCatalog::RULESET_VERSION` bumped `2026.09.2` → `2026.09.3`
(matcher-behavior change, not a release marker).

- **`laradogs.security.filesystem.tainted-path`**: every sink
  (`file_get_contents`/`file_put_contents`/`unlink`/`fopen`/`Storage::*`)
  rewritten as fixed-arity alternatives (explicit metavariables, never a
  trailing `...`) plus `focus-metavariable: $X`, fixing a confirmed real
  false positive where a server-generated, `Str::uuid()`-based PATH with
  tainted CONTENT was flagged as path traversal. Also sets
  `options: { taint_assume_safe_functions: true }`, fixing a second real
  false-positive class (a tainted argument passed to a helper/service
  method, or used in an Eloquent `->where($tainted)->get()` call, whose
  RETURN VALUE was conservatively treated as tainted even though the
  callee returns a fresh, server-generated value) — confirmed empirically
  to not weaken this rule's already-tested direct-passthrough/
  concatenation/interpolation guarantees. Accepted trade-off, documented:
  a genuinely transparent pass-through wrapper is no longer caught either.
- **`laradogs.security.sql.tainted-raw-query`**: the same
  fixed-arity-plus-`focus-metavariable` fix on every sink
  (`whereRaw`/`orderByRaw`/`selectRaw`/`havingRaw`/`DB::raw`), fixing the
  exact real false positive this rule produced against a bound
  `whereRaw('... = ?', [$tainted])` call — the safe pattern this rule's
  own remediation text recommends — and eliminating the "chain-cascade"
  duplicate finding this same root cause produced on a later, fully
  literal `selectRaw`/`orderByRaw` chained off the same query-builder
  variable. Also confirmed to already eliminate a third real shape (a
  tainted value reaching only a ternary condition inline inside the
  sink's own raw-string argument) with no further change needed.
- **`laradogs.performance.eloquent.unbounded-all`**: the receiver
  `metavariable-regex` fully anchored (`^[A-Z]` → `^[A-Z][A-Za-z0-9_]*$`),
  fixing a confirmed real false positive where `$MODEL` bound an entire
  preceding fluent chain (`SomeService::...->values()->all()`,
  `DB::table(...)->pluck(...)->all()`) whose first character happened to
  be uppercase, mistaking `Collection::all()` for `Model::all()`.
- 11 new regression fixtures across the three rules' existing fixture
  files, plus 1 new mandatory positive (`whereRaw` via concatenation) — no
  existing fixture/test changed behavior. Full real-binary rule quality
  gate (`LARADOGS_TEST_REAL_SEMGREP=1`) re-run: 10/10 passing, 25 findings
  across all fixtures combined (was 24), 0 errors.
- Retested the same real project after this fix: scan completed, coverage
  Explicit, all findings re-triaged. See
  `docs/auditing/rules/security-rules.md` and
  `docs/auditing/rules/performance-rules.md` for the full before/after
  account.

## [Unreleased] — Phase 5: Static Analysis Foundation + Semgrep

### Added

- `App\Audit\Analyzers\Semgrep\SemgrepAnalyzer` — the third real analyzer,
  and the first Static Application Security Testing (SAST) one: runs
  LaraDogs' own small, bundled Semgrep ruleset (`resources/audit/semgrep/rules/`,
  3 rules) against a project's first-party PHP source, via the SAME real
  `SymfonyProcessRunner`. Proves the full Discovery → Engine → Semgrep →
  `FindingCandidate` → Finding persistence/lifecycle vertical, deliberately
  NOT yet a comprehensive Laravel-aware rule library.
- `SemgrepBinaryResolver`, `SemgrepTargetCollector`, `SemgrepParser`,
  `SemgrepCoverageEvaluator`, `SemgrepRuleCatalog`/`SemgrepRule`,
  `SemgrepFinding`/`SemgrepScanReport` — the supporting classes, mirroring
  the shape of the existing Composer/npm analyzers without sharing a base
  class with them.
- `AnalyzerCoverage::Explicit` is genuinely exercised in production for
  the first time: `SemgrepCoverageEvaluator` declares it only when a run
  reported zero operational errors/warnings and no non-benign skipped
  files, falling back to `Unknown` the moment there's any doubt. All 5
  required Finding lifecycle cases (verified resolution, removed rule,
  failed analyzer, unknown coverage, regression) are proven against the
  real analyzer across successive scans.
- `config('laradogs.semgrep.*')` — binary override, timeout, per-file
  timeout, max target bytes, and a forced `SEMGREP_SETTINGS_FILE` path.
- `docs/auditing/analyzers/semgrep.md`, `docs/auditing/static-analysis.md`,
  `docs/auditing/rules.md`, and
  [ADR-0012](docs/architecture/decisions/ADR-0012-trusted-static-analysis-rules.md)
  (Trusted Static Analysis Rules).
- Semgrep added to the Docker `runtime` stage via an isolated Python
  virtualenv (`/opt/semgrep-venv`), version-pinned via a new
  `SEMGREP_VERSION` build ARG — verified with a real `docker compose
build` + running container: no Composer/npm regression,
  `composer-audit` + `npm-audit` + `semgrep` all coexist in one run,
  read-only-mounted target confirmed byte-for-byte unchanged.
  Image-size impact: ~382MB (798MB → 1.18GB).
- 69 new tests (329 total; 318 passing + 11 opt-in real-network/
  real-binary tests skipped by default), including 4 opt-in tests against
  the real `semgrep` binary and one comprehensive multi-scan Finding
  lifecycle test.

### Fixed / researched

- **A real, reproduced trust gap**: pointing `semgrep scan` at a
  directory lets the target's own `.semgrepignore` hide a
  genuinely-vulnerable file from analysis entirely, with zero error
  signal. Mitigated by never doing that: `SemgrepTargetCollector`
  performs LaraDogs' own bounded, symlink-rejecting, realpath-contained
  file walk and passes every collected file as an explicit `semgrep scan`
  argv target instead — verified to bypass `.semgrepignore`/`.gitignore`
  regardless of what either file says.
- Confirmed Semgrep's `check_id` embeds a mangled form of the `--config`
  path's directory unless invoked with a bare filename from that file's
  own directory as cwd — `SemgrepParser` matches `check_id` against the
  known rule catalog by exact-or-suffix match rather than ever trusting
  it verbatim.
- Confirmed `paths.skipped` (size-limit skips, etc.) is silently empty
  without `--verbose`, even though the skip genuinely happened —
  `SemgrepAnalyzer` always passes `--verbose` for this reason.
- Confirmed Semgrep's own `extra.lines`/`extra.fingerprint` require an
  account login and are unusable in LaraDogs' unauthenticated,
  local-CLI-only mode — code snippets are read directly from the source
  file instead.

## [Unreleased] — Phase 4.2.1: Npm Registry Trust Hardening

### Fixed

- **Closed a real, confirmed gap in `npm-audit`'s registry-trust
  mitigation**: a target project's own `.npmrc` `proxy=`/`https-proxy=`
  setting could reroute an otherwise correctly `--registry=`-pinned audit
  request through a server the target controls — reproduced directly
  with a local test listener (two real `CONNECT registry.npmjs.org:443`
  attempts observed under the original Phase 4.2 code). Fixed by always
  passing `--proxy=false --https-proxy=false --strict-ssl=true`
  (verified to make the exact same hostile fixture produce zero requests
  to the listener), with an operator-only override
  (`config('laradogs.npm.proxy')`/`https_proxy`, never target-supplied)
  for a legitimate trusted proxy. A companion investigation into scoped
  registry overrides (`@scope:registry=...`) found — from the installed
  npm's own source (`@npmcli/arborist/lib/audit-report.js`) and confirmed
  by reproduction with a real local HTTP server — that `npm audit`
  submits its ENTIRE dependency tree to a single registry and never
  queries a per-scope registry for advisory data at all, so this specific
  vector required no mitigation; recorded explicitly so it isn't mistaken
  for an unaddressed gap.

### Added

- `App\Audit\Analyzers\Npm\NpmConfigInspector` — a minimal, read-only,
  size-bounded (64KB) static scanner for a target's `.npmrc`, detecting
  only `cafile`/`cert`/`certfile`/`key`/`keyfile` (the one npmrc
  trust-relevant key category not neutralized by a flag — `cafile` was
  confirmed, from source, to let a target's `.npmrc` make npm read an
  arbitrary file from disk into TLS trust material). Reports key
  _names_ only, never values. `NpmAuditAnalyzer::run()` fails closed with
  a `UNSAFE_TARGET_NPM_CONFIG` diagnostic whenever any is present, or the
  `.npmrc` exceeds the size cap — before ever invoking `npm` at all.
  Never a Finding; this is an operational/trust failure, not a security
  finding about the target's own code.
- 20 new tests, including a security-regression test that opens a real
  local TCP socket, points a target's `.npmrc` scoped-registry AND proxy
  settings at it, runs a real `npm audit`, and asserts the socket's
  accept queue is empty afterward — literal proof of zero bytes reaching
  a hostile endpoint, not just an inferred-safe end result.

## [Unreleased] — Phase 4.2: Npm Audit Analyzer

### Added

- **The second real analyzer, `App\Audit\Analyzers\Npm\NpmAuditAnalyzer`**,
  reusing `SymfonyProcessRunner` unchanged: runs
  `npm audit --json --package-lock-only --ignore-scripts --registry=<pinned>`
  against a project's locked npm dependencies. Applicable only with a
  valid `package.json` **and** an npm-native lockfile
  (`package-lock.json` or `npm-shrinkwrap.json` — never confusing
  `yarn.lock`/`pnpm-lock.yaml` for one); available only when a resolved
  `npm` binary (LaraDogs' own config/PATH, never
  `./node_modules/.bin/npm`) reports `>= 7.0.0` (the version that
  introduced the `auditReportVersion: 2` schema this analyzer's parser
  targets). Never runs `npm install`/`npm ci`/`npm audit fix`. A
  dedicated `NpmAuditParser` extracts only genuine advisory objects from
  each package's mixed `via` array (never fabricating a finding from a
  plain-string meta-vulnerability cross-reference to another package's
  own entry) and fails closed on malformed output, an unrecognized/older
  schema, or npm's distinctly-shaped registry/network-error response —
  none of which npm's own ambiguous exit codes (a registry failure and
  "vulnerabilities found" both exit `1`) can distinguish on their own.
  Rule identity is `{packageName}:{source}` (stable, deterministic);
  severity maps npm's own `info`/`low`/`moderate`/`high`/`critical`
  directly, with `Severity::Unknown` for anything else; confidence is
  always `High`; coverage is always `Unknown` (same reasoning as
  `composer-audit`, plus this phase's own registry-redirection finding
  below). `fixAvailable` is preserved as metadata, never executed.
- **The central research finding of this phase**: a target project's own
  `.npmrc` is read automatically by `npm audit` (no flag disables this)
  and could redirect the registry query to a server the target controls
  — mitigated by always pinning `--registry=` as an explicit CLI flag
  (npm's own documented config precedence puts CLI flags above `.npmrc`
  files), verified to make a hostile project-level registry override
  ineffective. `NPM_CONFIG_USERCONFIG`/`NPM_CONFIG_CACHE` are always
  forced (not merely forwarded) to LaraDogs-controlled paths under its
  own `storage_path()`, so a developer's personal registry credentials
  can never reach the subprocess, in Docker or locally, with zero
  Docker-specific configuration needed. A residual, documented gap
  remains: scoped `@scope:registry=` overrides in the target's `.npmrc`
  are not neutralized by the main registry pin. `audit=false` in the
  target's `.npmrc` was confirmed NOT to suppress the explicit `audit`
  command (only an install-time courtesy check).
- **Discovery (Phase 1) gained `FrontendProfile.npmLockfile: Detection`**
  and a `npm-shrinkwrap.json` recognition fix in `NpmManifest` (a real
  gap — shrinkwrap-only projects previously reported no package manager
  at all).
- **Node/npm added to the Docker `runtime` stage** — installed directly
  (not copied from `builder`, unlike Composer's single-file binary;
  npm's own multi-file `/usr/lib/node_modules/npm/` tree doesn't allow
  that), reusing the existing `NODE_VERSION=22` build ARG. Verified: no
  Composer regression; ~+229MB image size.
- **Zero changes needed** to the `laradogs:audit` CLI, `ScanRunner`,
  `ScanRecorder`, or `FindingIngestor` to support a second analyzer — all
  were already generic. A new multi-analyzer coexistence test suite
  confirms `composer-audit` and `npm-audit` register under distinct ids
  with no collision, both run and persist independently in one scan, and
  one analyzer's `Failed` result never corrupts or blocks the other's.
- 51 new tests (260 total; 254 passing + 6 opt-in real-network tests
  skipped by default), including opt-in real-`npm`-binary proofs of the
  read-only-target guarantee, the malicious-lifecycle-scripts guarantee,
  and the combined hostile-`.npmrc` mitigation (fake registry +
  `audit=false` + fake token, still returns correct real data).

### Known limitations

- Coverage is always `Unknown` — npm findings do not auto-resolve yet.
- Scoped registry overrides (`@scope:registry=`) were believed at the
  time of this entry to not be neutralized by the main `--registry=`
  pin — **corrected in Phase 4.2.1 above**: further research proved this
  was never actually exploitable for `npm audit` specifically. The REAL
  gap this phase missed (`.npmrc` `proxy=`/`https-proxy=`) is fixed in
  that same entry.
- Workspaces are not specifically handled or tested.
- No dependency-deprecation handling for npm packages (separate concept
  from security advisories, not implemented even as a diagnostic).

## [Unreleased] — Phase 4.1: Composer Analyzer Deployment Readiness

### Fixed

- **The official Docker image now ships a working `composer` binary in
  its `runtime` stage** — a known gap left open at the end of Phase 4
  (`ComposerAuditAnalyzer` reported `Unavailable` inside that image).
  Reused from the `builder` stage (one pinned pull, not a second one);
  Composer version is now pinned (`COMPOSER_VERSION=2.10.3`, comfortably
  above `ComposerAuditAnalyzer::MIN_SUPPORTED_VERSION`'s `2.4.0` floor)
  instead of a floating `composer:2` tag. `COMPOSER_HOME` is set to a
  fixed, LaraDogs-controlled directory
  (`/home/laradogs/.composer`) via a container-level `ENV` — reaching the
  `composer` subprocess through the existing `process.env_allowlist`
  mechanism with zero analyzer code changes. Verified with a real
  `docker compose build` + a running (non-root, healthy) container:
  `composer --version` works, `ComposerAuditAnalyzer::availability()`
  reports `AVAILABLE`, and a real audit run against a **read-only-mounted**
  fixture succeeded, returning real advisories, with Composer's cache
  confirmed to land only under `/home/laradogs/.composer` — never inside
  `/app` or the mounted target. See
  [`docs/development/docker.md`](docs/development/docker.md#composer-in-the-runtime-image).

### Investigated (no code change)

- **Dependency/package-scoped `AnalyzerCoverage` for `composer-audit`.**
  Researched whether Composer's audit model justifies a coverage claim
  stronger than `Unknown` (`Full`, or a new package-scoped coverage
  concept). Confirmed by reading the exact pinned Composer source and by
  reproduction with the real binary: a target's own `composer.json` can
  disable its only advisory-capable repository
  (`"repositories": {"packagist.org": false}`) and receive a perfectly
  clean, valid-JSON, exit-0 audit result — even against a `composer.lock`
  locking a package version with real, known advisories. This rules out
  `AnalyzerCoverage::full()` and any new package-level coverage primitive
  for now: LaraDogs cannot currently verify, from `composer audit`'s
  output alone, that any advisory-capable repository was actually
  queried. `composer-audit` continues to always declare
  `AnalyzerCoverage::unknown()` — Composer-sourced findings still don't
  auto-resolve. See
  [`docs/auditing/analyzers/composer-audit.md`](docs/auditing/analyzers/composer-audit.md#dependency-coverage-research-phase-41).
  (Also confirmed, as good news: a project's `ignore-unreachable` policy
  setting does **not** suppress the `unreachable-repositories` signal
  this analyzer already fails closed on — it only controls whether
  Composer aborts hard vs. continues past the failure.)

### Added

- 3 new tests: `COMPOSER_HOME` environment-allowlist forwarding, a
  portable (non-Docker) filesystem-read-only target check, and (opt-in,
  real-network-gated) a real `composer audit` against a real
  filesystem-read-only target with `COMPOSER_HOME` pointed outside it,
  asserting the target is byte-for-byte unchanged afterward.

## [Unreleased] — Phase 4: Safe Process Execution + First Real Analyzer (Composer Audit)

### Added

- **The first real `Analyzer`, running end-to-end:**
  `App\Audit\Analyzers\Composer\ComposerAuditAnalyzer` runs
  `composer audit --locked --format=json --no-plugins --no-scripts`
  against a project's locked PHP dependencies and normalizes real
  security advisories into `FindingCandidate`s. Applicable only to a
  Composer project with a `composer.lock`; available only when a
  `composer` binary >= 2.4 resolves from LaraDogs' own config/PATH (never
  the target). Never runs `composer install`, never creates `vendor/`,
  never executes the target's `scripts`/plugins. Advisory rule identity
  is `{package_name}:{advisoryId}` (stable, deterministic); severity maps
  Composer's own (frequently null) `severity` field, with a new
  `Severity::Unknown` case for when it's absent; confidence is always
  `High` (reflects match confidence, not real-world impact); coverage is
  always declared `Unknown` (Composer has no "rules executed" universe to
  declare `Explicit`/`Full` from — a documented limitation, not a gap:
  Composer findings don't auto-resolve yet). Abandoned packages are
  reported as an informational diagnostic, never a `Finding`. Malformed/
  truncated JSON, a timeout, and unreachable advisory repositories all
  fail closed — never a false-clean scan. See
  [`docs/auditing/analyzers/composer-audit.md`](docs/auditing/analyzers/composer-audit.md).
- **The first real `ProcessRunner`:** `SymfonyProcessRunner`
  (`app/Audit/Engine/Process/`), built on Symfony Process (already a
  transitive dependency — no new package added). Argv-only (no shell
  string exists to inject into), a true environment allowlist (verified
  against Symfony's exact source — a naive `setEnv()` call alone would
  still leak the full parent environment), real timeout enforcement,
  output capping via a streaming callback with truncation reporting, and
  a `ProcessResult::processStartFailed()` distinguishing "never started"
  from any real exit code. See
  [`docs/development/process-execution.md`](docs/development/process-execution.md)
  and [ADR-0011](docs/architecture/decisions/ADR-0011-safe-external-process-execution.md).
- `App\Audit\Findings\Ingestion\ProducesFindingCandidates` and
  `App\Audit\Findings\Ingestion\ScanRunner` — the minimal orchestration
  seam connecting a real analyzer to Finding persistence without
  `App\Audit\Engine` ever depending on `App\Audit\Findings`.
- `Severity::Unknown` — a real domain need (a real advisory can carry no
  severity from its source), not an aesthetic addition.
- `php artisan laradogs:audit {path} [--json] [--analyzer=composer-audit]`
  — prints one real `AuditRunResult`; deliberately does not persist a
  `Scan` (see the analyzer doc for why).
- `config/laradogs.php` gained `process.*` (timeout, max output bytes,
  env allowlist) and `composer.*` (binary override, timeout) sections.
- 45 new tests (206 total; 205 passing + 1 opt-in real-network test
  skipped by default): the real `ProcessRunner` against controlled PHP
  fixture scripts (including a literal shell-metacharacter argv-injection
  proof), a dedicated `ComposerAuditParser` test suite against synthetic
  Composer JSON fixtures, `ComposerAuditAnalyzer` tests against a fake
  `ProcessRunner`, one full end-to-end pipeline test, a static
  dependency-direction guard, and an extension of the existing
  no-shell-execution guard to cover the new `app/Audit/Analyzers/`
  namespace.

### Known limitations

- Composer-sourced findings never auto-resolve yet (coverage is always
  `Unknown` — see the analyzer doc's rationale).
- The `composer` binary is not present in the Docker `runtime` image
  today (only the discarded `builder` stage has it) — documented in
  `docs/development/docker.md`, not fixed this phase (no Docker
  redesign).
- Abandoned packages are diagnostic-only, never a `Finding`.

## [Unreleased] — Phase 3.1: Safe Finding Resolution Coverage

### Fixed

- **Closed a real auto-resolution gap:** a finding was previously eligible
  for auto-resolution whenever its analyzer completed a scan with
  `Passed` and wasn't re-observed — but `Passed` only means the analyzer
  ran without error, not that it verified the specific rule behind an
  existing finding. A rule removed/disabled/not-loaded while its analyzer
  still exits cleanly could have silently (and wrongly) auto-resolved a
  still-present issue. `FindingReconciler` now also requires the
  execution's declared coverage to verify the finding's `rule_id` — see
  [ADR-0010's amendment](docs/architecture/decisions/ADR-0010-finding-identity-occurrences-and-lifecycle.md#amendment-phase-31-coverage-gated-auto-resolution).

### Added

- `App\Audit\Engine\Execution\AnalyzerCoverage`/`CoverageMode`
  (`Unknown|Explicit|Full`) — an analyzer's own declaration of what its
  execution actually verified, on `AnalyzerResult`
  (`App\Audit\Engine\Execution\AnalyzerExecution::coverage()` for
  convenient access), independent of `status`. Defaults to `Unknown`
  everywhere; never inferred as `Full`/`Explicit` just because an
  analyzer reported `Passed`. `rulesetVersion` travels along for
  provenance only — never consulted to authorize a resolution.
- A new migration adding a nullable `coverage` JSON column to
  `scan_analyzer_executions` (the existing table's migration was not
  edited — schema history preserved) and
  `App\Models\Audit\Casts\AsAnalyzerCoverage` to cast it.
- 16 new tests covering all of: explicit coverage naming/not-naming the
  rule, unknown coverage, no coverage at all, failed/timed-out/
  unavailable analyzers even with matching coverage, full coverage,
  ruleset-version independence (different version + matching coverage
  resolves; same version + non-matching coverage doesn't), analyzer
  boundaries (one analyzer's coverage never resolves another's finding),
  suppressed statuses remaining untouched under full coverage, and a
  full-pipeline reproduction of the original bug (rule disabled between
  two real scans).
- Existing fake analyzers (`AlwaysPassAnalyzer`) updated to accept an
  explicit, optional coverage — defaulting to `Unknown`, never quietly
  upgraded to keep old assertions passing.

### Clarified

- **Concurrency documentation corrected for precision:**
  `lockForUpdate()` only locks a row that already exists — it cannot
  prevent two transactions that both observe "no matching Finding yet"
  from both attempting an insert. The database's unique constraint on
  `(project_id, fingerprint, fingerprint_version)` is the actual final
  guarantee against a duplicate (a losing insert throws a
  `QueryException`, handled today as a loud scan failure, not a silent
  retry) — now verified directly by a dedicated test and documented
  accurately in
  [`findings-lifecycle.md`](docs/auditing/findings-lifecycle.md#concurrency-limits).

### Notes

- No new ADR — this amends ADR-0010 directly (a refinement of the exact
  decision it already owns).
- No real scanner/analyzer integration — coverage is exercised entirely
  with synthetic candidates and fake analyzers.
- No new Composer/npm dependencies.

## [Unreleased] — Phase 3: Finding Domain + Persistence + Lifecycle

### Added

- **Persistent Finding domain** (`app/Audit/Findings/`,
  `app/Models/Audit/`): `Project`, `Scan` (immutable, with a
  `ProjectProfile` JSON snapshot), `ScanAnalyzerExecution` (one row per
  Phase 2 `AnalyzerExecution` — what makes auto-resolution safety
  possible), `Finding` (stable cross-scan identity), `FindingOccurrence`
  (per-scan evidence, never overwritten), `FindingStatusHistory`
  (append-only lifecycle audit trail). All migrations use portable
  Laravel primitives only — no vendor-specific enum types/JSON
  operators/generated columns (ADR-0007).
- `FindingCandidate` — the scanner-agnostic normalized observation DTO a
  real analyzer (Phase 4+) will eventually produce; no real analyzer
  produces one yet.
- `Fingerprinter` — versioned (`v1`), deterministic, line-number-
  independent identity from analyzer id + rule id + normalized file path
    - normalized code snippet. The project is deliberately not part of the
      hash — scoped instead via a `(project_id, fingerprint,
fingerprint_version)` unique constraint.
- `Severity` (`CRITICAL|HIGH|MEDIUM|LOW|INFO`), `Confidence`
  (`HIGH|MEDIUM|LOW`, tracked independently of severity), `FindingStatus`
  (`OPEN|CONFIRMED|RESOLVED|ACCEPTED_RISK|FALSE_POSITIVE|IGNORED` — no
  separate `REGRESSED` status; a regression is a history event, not a
  status a finding sits in), `ScanStatus`, `ActorType`.
- `FindingLifecycleService` — the only code path allowed to change a
  Finding's status; requires a reason for
  `ACCEPTED_RISK`/`FALSE_POSITIVE`/`IGNORED`, writes append-only history.
- `FindingIngestor` — find-or-create by fingerprint (locked, per-project),
  records a `FindingOccurrence` per scan, reopens a `RESOLVED` finding
  automatically on reappearance, and never lets re-observation silently
  overturn a suppressed manual status.
- `FindingReconciler` — the safety-critical auto-resolution sweep: a
  finding is only ever auto-resolved when its own analyzer completed the
  scan with `Passed` and it wasn't re-observed. An analyzer that didn't
  run, wasn't applicable, was unavailable, failed, or timed out leaves its
  findings completely untouched.
- `EvidenceRedactor` — conservative, defense-in-depth masking (AWS-style
  access key ids, obvious `SOMETHING_SECRET=value` assignments) applied to
  persisted code snippets/context/metadata before they ever hit the
  database.
- `ScanRecorder` — ties Phase 1 (Discovery) + Phase 2 (Engine) + Phase 3
  (persistence) together end-to-end: opens a scan, persists analyzer
  executions, ingests candidates, reconciles, marks the scan
  Completed/Failed.
- `config/laradogs.php` — a placeholder `version` string recorded on every
  scan (no release/tagging scheme exists yet).
- 49 new Pest tests (`tests/Unit/Audit/Findings/`,
  `tests/Feature/Audit/Findings/`) covering fingerprint determinism/line-
  movement-resilience, redaction, ingestion (creation, reuse, line
  movement, different rule/project), lifecycle transitions and required
  reasons, auto-resolution safety (passed/failed/unavailable/timed-out/
  not-run analyzers), suppressed-status persistence, regression/reopen,
  and a dedicated no-target-execution test extending Phase 1's guarantee
  through the full Discovery → Engine → Findings pipeline.

### Notes

- No real scanner integration (`composer audit`, `npm audit`, PHPStan,
  Semgrep, Trivy, OSV-Scanner) — that's Phase 4. Every `FindingCandidate`
  in this codebase is synthetic, used only in tests.
- No dashboard, no MCP server, no CLI for findings (not required this
  phase — domain services and tests are sufficient).
- No new Composer/npm dependencies — ULIDs use Laravel's native
  `HasUlids`.

## [Unreleased] — Phase 2: Audit Engine Foundation

### Added

- **Audit Engine foundation** (`app/Audit/Engine/`): consumes Phase 1's
  `ProjectProfile` to decide which analyzers apply
  (`Applicability`/`ApplicabilityStatus`) and are actually runnable on
  this host (`Availability`/`AvailabilityStatus`), builds an inspectable
  `AuditPlan` (never executes anything while planning), executes it, and
  normalizes every outcome — success, reported failure, thrown exception,
  timeout, unavailable, not-applicable, and (new) fail-fast skip — into an
  `AuditRunResult`. See
  [`docs/auditing/audit-engine.md`](docs/auditing/audit-engine.md) and
  [ADR-0009](docs/architecture/decisions/ADR-0009-audit-engine-foundation.md).
- `Analyzer` contract (`id()`/`name()`/`category()`/`applicability()`/
  `availability()`/`run()`), `AnalyzerId`, `AnalyzerCategory` (mirrors the
  planned `Finding` category list), `AnalyzerRegistry` (duplicate-id
  guard, deterministic registration order).
- `ExecutionStatus` (`Planned`/`Passed`/`Failed`/`TimedOut`/`Skipped`/
  `NotApplicable`/`Unavailable`), `AnalyzerResult`, `AnalyzerDiagnostic`
  (analyzer-execution problems — explicitly distinct from a future
  `Finding`), `AuditContext`, `AuditExecutionSettings` (`continueOnFailure`,
  default `true`, actually enforced in both directions — a real fail-fast
  path, not just a documented intent).
- `ProcessRunner`/`ProcessCommand`/`ProcessResult` contract
  (`app/Audit/Engine/Process/`) — the future real-process-execution
  boundary (Phase 4+), recorded now with **zero implementation**: argv-only
  (no shell string field), explicit working directory/environment/timeout.
  Nothing in this phase constructs or calls one.
- 8 synthetic `Analyzer` implementations under
  `tests/Support/Engine/Analyzers/` (never autoloaded in production):
  `AlwaysPassAnalyzer`, `AlwaysFailAnalyzer`, `ThrowingAnalyzer`,
  `TimedOutAnalyzer`, `UnavailableAnalyzer`, `NotApplicableAnalyzer`,
  `LaravelOnlyAnalyzer`/`NodeOnlyAnalyzer` (real applicability against
  real Phase 1 fixtures), `SpyAnalyzer`.
- 31 new Pest tests covering the registry, applicability vs. availability,
  planning, execution (success/failure/exception/timeout/continue-on-
  failure/fail-fast/deterministic ordering), serialization, a static
  no-shell-execution scan of the engine's own source, and a dedicated test
  extending Phase 1's no-code-execution guarantee through the new
  Discovery → AuditContext → AuditEngine pipeline.

### Notes

- No real scanner integration (`composer audit`, `npm audit`, PHPStan,
  Semgrep, Trivy, OSV-Scanner) — that's Phase 4. This phase validated the
  orchestration contract against synthetic analyzers only.
- No `Finding`/`Scan` model, no persistence — that's Phase 3.
- No CLI this phase (`laradogs:audit-plan` was considered and deliberately
  not built — see the Phase 2 report/`audit-engine.md` for why: with zero
  real analyzers registered in production, it would only ever show an
  empty plan).
- No new Composer/npm dependencies.

## [Unreleased] — Phase 1: Project Discovery Engine

### Added

- **Project Discovery Core** (`app/Audit/Discovery/`): a static,
  evidence-based stack detector that inspects a directory and produces a
  normalized `ProjectProfile` — Laravel version, Blade/Livewire/Inertia/
  React/Vue/TypeScript, testing tools (Pest/PHPUnit/Playwright/Vitest/
  Jest/Cypress), Docker/GitHub Actions/GitLab CI presence, Redis/queue/
  scheduler hints, and database driver hints. Never executes anything
  from the inspected project — see
  [`docs/auditing/project-discovery.md`](docs/auditing/project-discovery.md)
  and [ADR-0008](docs/architecture/decisions/ADR-0008-static-project-discovery.md).
- Every detection carries an explicit `detected`/`not_detected`/`unknown`/
  `invalid`/`unsupported` status (`Detection`/`VersionDetection` value
  objects) instead of a plain boolean, so "no evidence" is never confused
  with "evidence of absence."
- `laradogs:inspect {path} [--json]` Artisan command — thin CLI adapter
  over Project Discovery, human-readable output by default or the full
  `ProjectProfile` as JSON.
- 13 synthetic project fixtures under `tests/Fixtures/discovery/`
  (Laravel+Blade, Laravel+Livewire, Laravel+Inertia+React+TS,
  Laravel+Inertia+Vue, Laravel API-only, plain PHP Composer, Node-only,
  empty directory, malformed composer.json/package.json, with/without
  composer.lock, a full-stack fixture, and a no-code-execution fixture).
- 26 new Pest tests (`tests/Unit/Audit/Discovery/`,
  `tests/Feature/Console/`), including a dedicated test proving
  `composer.json`/`package.json` scripts found in an inspected project
  are never executed.

### Notes

- No scanners run, no `Finding`/`Scan` model, no persistence, no MCP
  server, no dashboard — this is stack detection only, not auditing. See
  [`docs/roadmap/phases.md`](docs/roadmap/phases.md) for what's still
  ahead.

## [Unreleased] — Phase 0.1: Persistence Strategy Correction

### Changed

- LaraDogs' own persistence is now documented and decided as
  database-agnostic: SQLite, MySQL, MariaDB, and PostgreSQL are all
  officially supported (`config/database.php` already wires all four via
  standard Laravel configuration; no code changes were needed). SQLite
  remains the Quick Start default for zero-configuration setup, not an
  architectural requirement.
- Deployment profiles (Personal/Server) no longer imply a specific
  database vendor — see
  [ADR-0007](docs/architecture/decisions/ADR-0007-database-agnostic-persistence.md),
  which supersedes the database-vendor portion of
  [ADR-0005](docs/architecture/decisions/ADR-0005-persistence-and-deployment-profiles.md)
  (ADR-0005's Docker/deployment-profile-shape decision is unchanged).
- Updated README, architecture overview/components, and development
  setup/Docker docs to reflect the above and to distinguish
  architecturally-supported databases from the current Docker quick-start
  image (which still only bundles the SQLite PHP extension).

### Notes

- No Docker image/service changes in this pass — only documentation
  comments. Adding MySQL/PostgreSQL PHP extensions to the runtime image is
  tracked as future work in ADR-0007.
- No Phase 1 (Project Discovery) or later feature was implemented.

## [Unreleased] — Phase 0: Discovery / Architecture / Bootstrap

### Added

- Laravel 13 application bootstrapped via the official React + Inertia
  starter kit (Inertia 3, React 19, TypeScript, Tailwind 4, shadcn/ui),
  with Fortify-based authentication (login, registration, password reset,
  email verification, two-factor auth, passkeys).
- SQLite as the default database ("Personal" deployment profile).
- Pest as the testing framework (39 starter-kit tests passing).
- Docker Compose setup for local self-hosted use: multi-stage `Dockerfile`
  (non-root runtime), `docker/entrypoint.sh` (idempotent bootstrap),
  `/up` healthcheck.
- Documentation set: architecture overview/components/data-flow/security
  model, six ADRs, development guides (setup, Docker, testing,
  conventions), planned auditing domain docs (findings/severity/
  confidence/suppressions), MCP integration doc, and roadmap.
- This `CHANGELOG.md`, `CONTRIBUTING.md`, `SECURITY.md`, and `LICENSE`
  (MIT).

### Notes

- No audit-domain code (scanners, `Finding`/`Scan` models, MCP server,
  CLI, Dashboard beyond starter-kit pages) exists yet — see
  [`docs/roadmap/phases.md`](docs/roadmap/phases.md).
- See the Phase 0 report (delivered alongside this change) for the full
  environment/decision/validation record.
