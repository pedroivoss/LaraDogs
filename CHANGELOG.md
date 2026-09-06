# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
LaraDogs does not yet have versioned releases (pre-1.0, early development)
— entries are grouped by roadmap phase until the first tagged release.

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
