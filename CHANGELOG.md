# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
LaraDogs does not yet have versioned releases (pre-1.0, early development)
— entries are grouped by roadmap phase until the first tagged release.

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
