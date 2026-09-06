# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
LaraDogs does not yet have versioned releases (pre-1.0, early development)
— entries are grouped by roadmap phase until the first tagged release.

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
