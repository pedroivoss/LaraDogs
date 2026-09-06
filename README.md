# LaraDogs

**Watch over your Laravel applications.** _(tagline — provisional)_

**Status: Early Development.** This repository currently contains a
bootstrapped Laravel application (Phase 0), a static project stack
detector (Phase 1), an analyzer orchestration foundation exercised only
with synthetic analyzers (Phase 2), and a persistent Finding domain/
lifecycle fed only by synthetic observations (Phase 3) — **no real
scanner produces a finding automatically yet.** If you're looking for a
working security scanner, this isn't one yet — see
[Current Capabilities](#current-capabilities) for exactly what exists
today.

## The problem

Laravel applications accumulate security issues, bugs, performance
problems, outdated dependencies, and configuration drift — the same way
any codebase does — but there's no single, self-hosted, Laravel-aware tool
that pulls the existing best-in-class scanners (`composer audit`,
`npm audit`, PHPStan/Larastan, ESLint, Semgrep, OSV-Scanner, Trivy,
Pest/PHPUnit) into one place, normalizes their output, tracks it over
time, and explains _why_ something matters in Laravel/Inertia/Livewire
terms specifically.

## Product vision

LaraDogs aims to be an open-source, self-hosted **Application
Intelligence** platform for Laravel applications (including those using
Blade, Livewire, Inertia, React, Vue, TypeScript, Vite, Docker, Redis, and
GitHub Actions) that detects, organizes, tracks, and explains:

- security vulnerabilities
- likely bugs
- performance problems
- vulnerable dependencies
- code quality issues
- insecure configuration
- test gaps
- regressions between audits

## Principles

1. **The Audit Core has no required AI dependency.** A normal audit must
   run locally, deterministically — no OpenAI, Anthropic, Gemini, or any
   LLM API key required, ever, for the core to work. AI is an _optional_
   integration on top, primarily through MCP. See
   [ADR-0002](docs/architecture/decisions/ADR-0002-application-architecture.md).
2. **Don't reinvent scanners.** LaraDogs orchestrates mature tools
   (PHPStan/Larastan, ESLint, Semgrep, OSV-Scanner, Trivy, `composer
audit`, `npm audit`, Pest/PHPUnit, and more over time) rather than
   reimplementing static analysis engines. The value is in detection,
   normalization, correlation, deduplication, Laravel-aware rules, and
   history. See [ADR-0004](docs/architecture/decisions/ADR-0004-scanner-execution-strategy.md).
3. **Findings never silently disappear.** Every finding has a lifecycle
   (open → confirmed/resolved/accepted-risk/false-positive/ignored) and a
   fingerprint that survives line-number churn, so history and regression
   tracking are meaningful. See
   [ADR-0003](docs/architecture/decisions/ADR-0003-finding-domain-model.md).
4. **Analyzed code is untrusted.** LaraDogs will eventually run scanners
   against arbitrary third-party repositories; execution must be isolated
   (timeouts, resource limits, non-root, no path traversal) — see
   [`docs/architecture/security-model.md`](docs/architecture/security-model.md).
5. **CLI, MCP, Dashboard, and CI/CD are interfaces, not the product.** None
   of them are required for the Audit Core to function. See
   [ADR-0002](docs/architecture/decisions/ADR-0002-application-architecture.md).

## Architecture (high level)

```
                       Audit Core
                           |
       +-------------------+-------------------+
       |                   |                   |
    Security             Bugs             Performance
       |                   |                   |
 Dependencies           Quality          Configuration
       |                   |                   |
       +-------------------+-------------------+
                           |
                         Tests
                           |
                           v
                       Findings
                           |
              +------------+------------+
              |            |            |
             CLI          MCP       Web Dashboard
                                       |
                                      API
                                       |
                                     CI/CD
```

Full detail: [`docs/architecture/overview.md`](docs/architecture/overview.md),
[`components.md`](docs/architecture/components.md),
[`data-flow.md`](docs/architecture/data-flow.md).

## Current capabilities

**Implemented:**

- **Project stack discovery** (Phase 1) — point `laradogs:inspect` at any
  directory and get back a normalized report of what it detects: Laravel
  version, Blade/Livewire/Inertia/React/Vue/TypeScript, testing tools
  (Pest/PHPUnit/Playwright/Vitest/Jest/Cypress), Docker/CI presence, and
  database driver hints. Static evidence only — **it never executes
  anything from the inspected project.** This is stack detection, **not
  auditing**: no scanners run, no vulnerability is reported, no `Finding`
  is produced. See
  [`docs/auditing/project-discovery.md`](docs/auditing/project-discovery.md).
- **Audit Engine foundation** (Phase 2) — the orchestration layer that
  will decide which analyzers apply to a `ProjectProfile`, check whether
  each is actually runnable on the host, build an inspectable plan, run
  it, and normalize every outcome. Exercised with synthetic analyzers
  only — **no real scanner is registered or integrated yet.** This is
  architecture, **not scanners**: `composer audit`/`npm audit`/PHPStan/
  Semgrep/etc. still don't run. See
  [`docs/auditing/audit-engine.md`](docs/auditing/audit-engine.md).
- **Finding domain & lifecycle** (Phase 3, hardened in Phase 3.1) — a
  persistent `Project`/`Scan`/`Finding`/`FindingOccurrence` schema with a
  versioned, line-number-independent fingerprint, full status lifecycle
  (open → confirmed/resolved/accepted-risk/false-positive/ignored, with
  append-only history), and safe, coverage-gated auto-resolution: a
  finding is only ever auto-resolved when its own analyzer ran
  successfully **and explicitly declared that it verified this finding's
  rule** — never on failure/timeout/unavailable, and never on a clean run
  that says nothing about coverage (a rule being disabled/removed is not
  the same as it being fixed). **Still fed only by synthetic observations
  in tests — no real scanner exists to populate it automatically.** See
  [`docs/auditing/findings-lifecycle.md`](docs/auditing/findings-lifecycle.md).
- A Laravel 13 application with React + Inertia (official starter kit),
  Fortify-based authentication, and a single authenticated dashboard page
  (Phase 0).
- Configurable SQL persistence (SQLite by default for zero-config Quick
  Start; MySQL, MariaDB, and PostgreSQL are also officially supported — see
  [Database support](#database-support)), `/up` health check, Pest test
  suite.
- Docker Compose for local self-hosted use (non-root runtime container,
  idempotent entrypoint).
- The documentation and architectural decisions this README links to.

**Not yet implemented** (everything that makes LaraDogs actually useful
as a _security/quality tool_, beyond stack detection, orchestration
architecture, and the Finding domain):

- Any real scanner integration (`composer audit`, `npm audit`, PHPStan/
  Larastan, ESLint, Semgrep, OSV-Scanner, Trivy) — security scanning, bug
  detection, performance analysis. Nothing produces a real `Finding` yet.
- Laravel-aware rules, correlation/deduplication across scanners, a
  dedicated scan-to-scan comparison **report** (the underlying
  regression/reopen lifecycle exists; a NEW/RESOLVED/UNCHANGED/REGRESSED
  report view doesn't), quality gates.
- The real Dashboard (findings/scans/rules/reports/quality
  gates/integrations/MCP access/system/updates), the MCP server, MCP
  credentials, Git/CI continuous monitoring.

**Planned:** see [`docs/roadmap/roadmap.md`](docs/roadmap/roadmap.md) for
the full phase list.

**Experimental:** none yet.

## Database support

- SQLite
- MySQL / MariaDB
- PostgreSQL

All four are supported via standard Laravel configuration
(`DB_CONNECTION`) — no vendor-specific code exists or is planned for basic
persistence. Quick Start below defaults to SQLite for zero-configuration
setup; it's the easiest way to try LaraDogs, not an architectural
requirement. Deployment profiles (Personal/Server, as they materialize)
don't dictate a database vendor either — see
[ADR-0007](docs/architecture/decisions/ADR-0007-database-agnostic-persistence.md).

This is entirely separate from the database used by a project LaraDogs
audits: the Audit Core never assumes a target project's database vendor
matches LaraDogs' own.

The current Docker quick-start image only ships the SQLite PHP extension;
using MySQL/MariaDB/PostgreSQL today means running outside that image (see
[`docs/development/docker.md`](docs/development/docker.md)).

## Quick start

### Requirements

PHP 8.3+, Composer 2.x, Node 20+/npm (or Docker — see below).

### Without Docker

```bash
git clone <this-repo> laradogs && cd laradogs
composer install
cp .env.example .env
php artisan key:generate
npm install && npm run build
php artisan migrate
php artisan serve
```

Visit `http://localhost:8000`. See
[`docs/development/setup.md`](docs/development/setup.md) for day-to-day
commands (`composer run dev`, test/lint gates).

### With Docker

```bash
git clone <this-repo> laradogs && cd laradogs
cp .env.example .env
php artisan key:generate --show   # copy the output into APP_KEY in .env
docker compose up --build
```

See [`docs/development/docker.md`](docs/development/docker.md) for what
the image does and current limitations.

## Development

- [`docs/development/setup.md`](docs/development/setup.md)
- [`docs/development/docker.md`](docs/development/docker.md)
- [`docs/development/testing.md`](docs/development/testing.md)
- [`docs/development/conventions.md`](docs/development/conventions.md)
- [`CONTRIBUTING.md`](CONTRIBUTING.md)

## Security philosophy

LaraDogs is a security tool; its own security is architecture, not an
afterthought — untrusted analyzed code, secret redaction, MCP credential
scoping, and non-root execution are all designed in from Phase 0, even
where the corresponding feature (e.g. scanner sandboxing) doesn't exist
yet. Read [`docs/architecture/security-model.md`](docs/architecture/security-model.md)
for what's actually true today versus what's a binding future constraint,
and [`SECURITY.md`](SECURITY.md) to report a vulnerability.

## AI / MCP philosophy

AI is optional and additive, never required. The Audit Core must produce
a complete, useful audit with zero AI involvement. Where AI helps — mainly
through MCP, letting a coding agent query findings and remediation context
— it sits strictly on top of deterministic output the core already
produced, and it does not get to edit your project's code by default; the
calling agent does that, using context LaraDogs provides. See
[`docs/integrations/mcp.md`](docs/integrations/mcp.md) and
[ADR-0006](docs/architecture/decisions/ADR-0006-mcp-security-model.md).

## Roadmap

Phase 0 (bootstrap), Phase 1 (Project Discovery), Phase 2 (Audit Engine
Foundation), and Phase 3 (Finding Domain + Persistence) are complete.
Phases 4–13 (Security/Dependency Scanners through Hardening/Release) are
not started. Full list, current position, and items deliberately
deferred:
[`docs/roadmap/roadmap.md`](docs/roadmap/roadmap.md).

## Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md). Given the early stage, design
discussion on the roadmap/ADRs is likely more valuable right now than
large feature PRs.

## License

[MIT](LICENSE).
