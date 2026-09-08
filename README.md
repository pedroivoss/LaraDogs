# LaraDogs

**Watch over your Laravel applications.** _(tagline — provisional)_

**Status: Early Development.** This repository currently contains a
bootstrapped Laravel application (Phase 0), a static project stack
detector (Phase 1), an analyzer orchestration foundation (Phase 2), a
persistent Finding domain/lifecycle (Phase 3), and — as of Phase 4/4.2/5 —
three real, working scanners: `composer audit` for PHP dependency
vulnerabilities, `npm audit` for npm/Node.js dependency vulnerabilities,
and a Semgrep-based static analysis **foundation** (a small, bundled
ruleset proving the pipeline, not yet a comprehensive rule library), all
via the same real, safe process-execution boundary, all working in local
development AND inside the official Docker image.
**This is not yet a general security scanner** — Composer/npm dependency
auditing are real, and Semgrep is real but limited to a handful of
proof-of-concept rules; PHPStan/Larastan, ESLint, OSV-Scanner, Trivy, and
Pest/PHPUnit integrations, a comprehensive Laravel-aware Semgrep rule
library, correlation across scanners, complete SQL-injection/XSS
detection, and a complete Laravel ruleset do not exist yet. See
[Current Capabilities](#current-capabilities) for exactly
what exists today.

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
  decides which analyzers apply to a `ProjectProfile`, checks whether each
  is actually runnable on the host, builds an inspectable plan, runs it,
  and normalizes every outcome. As of Phase 5, three real analyzers are
  registered and coexist deterministically — see below. See
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
  the same as it being fixed). As of Phase 5, `composer-audit` and
  `npm-audit` findings are both real, though neither auto-resolves yet;
  `semgrep` findings are also real and DO safely auto-resolve — the first
  analyzer where this mechanism is exercised for real (see below). See
  [`docs/auditing/findings-lifecycle.md`](docs/auditing/findings-lifecycle.md).
- **Composer dependency vulnerability auditing** (Phase 4, Docker support
  in Phase 4.1) — point `laradogs:audit {path}` at any Composer project
  with a `composer.lock` and get back real security advisories from
  `composer audit`, via a real, argv-only, env-allowlisted,
  timeout-enforced, output-capped subprocess boundary
  (`SymfonyProcessRunner`) — never mutating the target, never running its
  plugins/scripts (`--no-plugins --no-scripts`, always), never running
  `composer install`. Verified by automated tests and by manual runs
  against a real `composer` binary with real network access, **both
  running LaraDogs locally and inside the official Docker image** (real
  `docker compose build` + running container, non-root, healthy, Composer
  2.10.3 available, read-only-mounted target audited successfully). See
  [`docs/auditing/analyzers/composer-audit.md`](docs/auditing/analyzers/composer-audit.md).
- **Npm dependency vulnerability auditing** (Phase 4.2) — point
  `laradogs:audit {path} --analyzer=npm-audit` (or omit `--analyzer` to
  run every applicable analyzer, Composer and npm together) at any npm
  project with a `package-lock.json`/`npm-shrinkwrap.json` and get back
  real security advisories from `npm audit`, through the SAME safe
  subprocess boundary — never running `npm install`/`npm ci`/
  `npm audit fix`, never executing the target's lifecycle scripts
  (`--ignore-scripts`, always), and never trusting the target's own
  `.npmrc`: the registry and proxy are always pinned via explicit
  `--registry=`/`--proxy=`/`--https-proxy=` flags (verified — including
  with a real local socket the test suite controls directly — to
  neutralize both a malicious project-level registry override and a
  malicious proxy redirect, the latter closed in a dedicated follow-up
  hardening pass), TLS trust material (`cafile`/`cert`/`key`/etc.)
  declared in a target's own `.npmrc` makes the scan fail closed rather
  than proceed, and `NPM_CONFIG_USERCONFIG`/`NPM_CONFIG_CACHE` are always
  forced to LaraDogs-controlled paths, so a developer's own personal
  registry credentials can never reach it. Also verified inside the
  official Docker image (Node/npm added to the runtime, no Composer
  regression). All three scanners are verified to coexist deterministically in
  the same project/scan. See
  [`docs/auditing/analyzers/npm-audit.md`](docs/auditing/analyzers/npm-audit.md).
- **Semgrep-based static analysis, with a first Laravel-aware ruleset**
  (Phase 5 foundation + Phase 6 rules) — point
  `laradogs:audit {path} --analyzer=semgrep` (or omit `--analyzer` to run
  all three analyzers together) at any PHP project and get back real
  matches from a small, LaraDogs-bundled Semgrep ruleset (12 rules today:
  `dd()`/`var_dump()`/`ray()` left in code, `eval()` usage, possible SQL
  injection via raw queries, possible Blade/XSS via raw output, possible
  OS command injection, possible path traversal, possible open redirect,
  possible mass assignment, an unsafe `APP_DEBUG` config default, and one
  conservative `Model::all()` performance hotspot), through the SAME safe
  subprocess boundary. **This is still a small, curated set — not a
  comprehensive security scanner** — see "Not yet implemented" below. The target project can never choose which rules run (no
  `.semgrep.yml` auto-discovery, no `--config auto`/remote Registry) and
  can never hide a file from analysis via `.semgrepignore`/`.gitignore`
  (verified by reproduction: LaraDogs builds its own explicit,
  symlink-rejecting, realpath-contained file list rather than pointing
  Semgrep at a directory). No Semgrep account/login/API key is ever
  required or used; telemetry is always disabled. This is the first
  analyzer to safely auto-resolve findings, since — unlike a
  dependency-advisory scanner — a static-analysis run has an enumerable
  "rules executed" universe to verify against. Also verified inside the
  official Docker image (an isolated Python virtualenv, no Composer/npm
  regression, ~382MB image-size impact). See
  [`docs/auditing/analyzers/semgrep.md`](docs/auditing/analyzers/semgrep.md)
  and [`docs/auditing/static-analysis.md`](docs/auditing/static-analysis.md).
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
as a _general_ security/quality tool, beyond `composer audit`/`npm audit`/
the Semgrep foundation):

- **A comprehensive Laravel-aware Semgrep rule library** — 12 bundled
  rules exist (3 proving the pipeline, 9 covering SQL raw-query/Blade
  raw-output/command execution/path traversal/open redirect/mass
  assignment), but each is narrowly scoped, not exhaustive coverage of its
  class. No complete SQL-injection detection, no complete
  XSS/unescaped-Blade-output detection, no authorization/CORS/Sanctum
  rules yet (authorization-bypass detection was evaluated and explicitly
  rejected as too false-positive-prone for a naive pattern — see
  [`docs/auditing/rules/security-rules.md`](docs/auditing/rules/security-rules.md)).
- Any other real scanner integration (`yarn audit`, `pnpm audit`, `bun`,
  PHPStan/Larastan, ESLint, OSV-Scanner, Trivy) — bug detection,
  performance analysis.
- Correlation/deduplication across scanners, a dedicated scan-to-scan
  comparison **report** (the underlying regression/reopen lifecycle
  exists; a NEW/RESOLVED/UNCHANGED/REGRESSED report view doesn't), quality
  gates.
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

## Try LaraDogs

As of Phase 6, LaraDogs has a first genuinely useful (if still small and
deliberately conservative) Laravel-aware ruleset — this is the first point
where running it against a **real** Laravel project (not just LaraDogs'
own fixtures) is worthwhile. Every command below was run against a real
project (this repository itself) before being documented — none are
aspirational.

```bash
# Stack detection only — no scanners run, nothing is persisted.
php artisan laradogs:inspect /path/to/your/laravel/project

# Run every applicable analyzer (composer-audit + npm-audit + semgrep,
# whichever apply to the target) and print findings — read-only, never
# persists a Scan.
php artisan laradogs:audit /path/to/your/laravel/project

# Run just one analyzer:
php artisan laradogs:audit /path/to/your/laravel/project --analyzer=composer-audit
php artisan laradogs:audit /path/to/your/laravel/project --analyzer=npm-audit
php artisan laradogs:audit /path/to/your/laravel/project --analyzer=semgrep

# Machine-readable output (includes a normalized `findings` array: rule
# id, severity, confidence, file, line, message, recommendation, CWE):
php artisan laradogs:audit /path/to/your/laravel/project --json
```

Human-readable output shows, per finding: severity, rule id, category,
confidence, `file:line`, and message — e.g.:

```
[HIGH] laradogs.security.sql.tainted-raw-query (security, confidence: medium)
  app/Http/Controllers/ReportController.php:42
  Possible SQL injection: a value that appears to come directly from user input ...
```

**A real, encountered limitation, not a hypothetical one:** Semgrep's own
default per-run timeout (60s, `laradogs.semgrep.timeout_seconds`) can be
too short for a large codebase — running `laradogs:audit` against this
very repository's own root (which includes a large `tests/` fixture tree)
took **~77 seconds** and needed the timeout raised:
`LARADOGS_SEMGREP_TIMEOUT_SECONDS=180 php artisan laradogs:audit .`. A
typical application-sized target without an unusually large `tests/`
directory should complete well within the default.

For the full guide — Docker usage, interpreting output, known
limitations, how to report a false positive, and the guarantee that your
project is never mutated — see
[`docs/testing/manual-audit.md`](docs/testing/manual-audit.md).

## Development

- [`docs/development/setup.md`](docs/development/setup.md)
- [`docs/development/docker.md`](docs/development/docker.md)
- [`docs/development/testing.md`](docs/development/testing.md)
- [`docs/development/conventions.md`](docs/development/conventions.md)
- [`CONTRIBUTING.md`](CONTRIBUTING.md)

## Security philosophy

LaraDogs is a security tool; its own security is architecture, not an
afterthought — untrusted analyzed code, secret redaction, MCP credential
scoping, and non-root execution are all designed in from Phase 0. As of
Phase 5, real scanner process execution exists (for `composer audit`,
`npm audit`, and `semgrep`) and follows that same philosophy: argv-only (no
shell), an explicit environment allowlist, real timeouts, output capping,
and never mutating or executing code from the analyzed project — for
npm specifically, this extends to never trusting the target's own
`.npmrc` (registry always pinned, personal credentials never forwarded;
see
[`docs/auditing/analyzers/npm-audit.md`](docs/auditing/analyzers/npm-audit.md#9-npm-configuration-security)),
and for Semgrep, to never letting the target choose its own rules or hide
a file from analysis via `.semgrepignore`/`.gitignore` (see
[`docs/auditing/analyzers/semgrep.md`](docs/auditing/analyzers/semgrep.md)
and [ADR-0012](docs/architecture/decisions/ADR-0012-trusted-static-analysis-rules.md))
(see
[`docs/development/process-execution.md`](docs/development/process-execution.md)).
Read [`docs/architecture/security-model.md`](docs/architecture/security-model.md)
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
Phase 4 (Security/Dependency Scanners) is in progress — `composer audit`,
`npm audit`, and Semgrep (foundation + a first Laravel-aware ruleset, 12
rules) are done (tracked in commit history/ADR notes as sub-phases
4/4.1/4.2/4.2.1/5/6); other scanners (PHPStan/Larastan, ESLint,
OSV-Scanner, Trivy) and the COMPREHENSIVE Laravel-aware Semgrep rule
library are not started. This
document's own coarse Phases 5–13 (Bug/Quality Analysis, Performance
Analysis, Dashboard, ...) are not started. Full list, current position,
and items deliberately deferred:
[`docs/roadmap/roadmap.md`](docs/roadmap/roadmap.md).

## Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md). Given the early stage, design
discussion on the roadmap/ADRs is likely more valuable right now than
large feature PRs.

## License

[MIT](LICENSE).
