# Project Discovery

**Status: Implemented (Phase 1).** This is the first real piece of the
Audit Core. It detects a project's stack; it does not audit it — no
scanners run, no `Finding` is produced, no vulnerability is reported. See
[ADR-0008](../architecture/decisions/ADR-0008-static-project-discovery.md)
for the security decision behind how it works, and
[`auditing/overview.md`](overview.md) for where Discovery fits in the
larger (still mostly unbuilt) audit pipeline.

## Objective

Given a directory, produce a normalized `ProjectProfile` describing what
stack that project appears to use — Laravel or not, which frontend
framework, which testing tools, which infrastructure files exist, which
database driver it declares — using only static evidence. Nothing is
executed to get there.

## Security model

The project being inspected is **untrusted input**, exactly as for the
scanners Phase 4 will eventually orchestrate (see
[`../architecture/security-model.md`](../architecture/security-model.md)
and [ADR-0004](../architecture/decisions/ADR-0004-scanner-execution-strategy.md)).
Discovery's specific guarantees:

- **No target code execution, ever.** No `composer`, `npm`, `npx`,
  `artisan`, `vendor/bin/*`, `Makefile` target, Docker build, or CI
  workflow is invoked. No PHP file from the target is `include`d,
  `require`d, or `eval`'d — files like `routes/console.php` are read as
  **plain text** and pattern-matched only.
- **Bounded, rooted filesystem access.** Every path is resolved with
  `realpath()` and checked to still fall inside the project root before
  it's touched — this rejects path traversal (`../../etc/passwd`) and a
  symlink planted inside the project that points somewhere else on disk.
  Reads are capped by size; recursive lookups (e.g. scanning
  `resources/views` for `.blade.php` files) are capped in depth and total
  entries visited. See `app/Audit/Discovery/Filesystem/ProjectFilesystem.php`.
- **`.env` is never read.** Only `.env.example` (never the real `.env`)
  is used, and only as a hint source — never for secrets, because
  Discovery never has a path to the real one.
- **No persistence.** Discovery runs entirely in memory; nothing is
  written to a database. Persisting a `ProjectProfile` (or a `Scan` built
  from one) is Phase 3 scope.
- **No dependency on the web UI, MCP, or a database.** The core
  (`app/Audit/Discovery/`) is plain PHP, callable from a CLI command, a
  future queued job, or a future HTTP endpoint identically.

See [Verification](#no-code-execution-guarantee-verification) below for
how the no-execution guarantee is tested.

## Architecture

```
ProjectDiscovery
    |
    +-- ProjectFilesystem        (bounded, rooted, read-only file access)
    |
    +-- ComposerManifest / NpmManifest   (safe JSON parsing of manifests)
    |
    +-- ComposerInspector         (PHP constraint, composer.json/lock presence)
    +-- LaravelInspector          (Laravel version, Blade, Livewire, Inertia
    |                              backend, known first-party packages)
    +-- FrontendInspector         (Node, package manager, Vite, React, Vue,
    |                              TypeScript, Tailwind, Inertia client)
    +-- TestingInspector          (Pest, PHPUnit, Playwright, Vitest, Jest,
    |                              Cypress)
    +-- InfrastructureInspector   (Docker, Docker Compose, GitHub Actions,
    |                              GitLab CI, Redis/queue/scheduler hints)
    +-- DatabaseInspector         (declared DB_CONNECTION from .env.example)
    |
    +-- ProfileBuilder            (assembles ProjectProfile, resolves ProjectType)
```

All of this lives under `app/Audit/Discovery/`, with no dependency on
`Illuminate\Http\*`, Eloquent, or any Laravel facade beyond plain PHP —
consistent with [ADR-0002](../architecture/decisions/ADR-0002-application-architecture.md)'s
Audit Core / Interface separation. `App\Console\Commands\InspectProjectCommand`
is a thin adapter with no detection logic of its own.

## `ProjectProfile` design

Every leaf value is one of two immutable value objects, never a plain
boolean — collapsing "not there" and "couldn't tell" into one bit would
misreport an absence of evidence as evidence of absence:

- **`Detection`** — a `status` (`detected` / `not_detected` / `unknown` /
  `invalid` / `unsupported`) plus optional `evidence` (a short pointer to
  what produced the result, e.g. `"composer.json: require.livewire/livewire"`).
- **`VersionDetection`** — the same `status`, plus a separate `constraint`
  (declared, e.g. `"^13.0"`) and `installedVersion` (resolved from a lock
  file, e.g. `"13.4.2"`) — a constraint is never promoted into a fake
  "installed" value.

Status meanings:

| Status         | Meaning                                                                                                                |
| -------------- | ---------------------------------------------------------------------------------------------------------------------- |
| `detected`     | Positive, evidence-backed detection.                                                                                   |
| `not_detected` | Evidence sources were readable and don't indicate the feature.                                                         |
| `unknown`      | No usable evidence source was available to decide either way (e.g. no `.env.example` to read a database default from). |
| `invalid`      | An evidence source exists but couldn't be parsed (e.g. malformed `composer.json`).                                     |
| `unsupported`  | Recognized but outside what this inspector evaluates.                                                                  |

`ProjectProfile` groups these into `backend`, `frontend`, `testing`,
`infrastructure`, and `database` sections, plus a top-level `type`
(`laravel` / `plain_php_composer` / `node_only` / `empty` / `unknown`) and
a list of `issues` (non-fatal problems noticed on a specific evidence
source, e.g. a malformed manifest) so callers can see _why_ related
detections came back `invalid`/`unknown` instead of guessing.

## Detection capabilities

### Backend

PHP constraint, Composer/composer.lock presence, Laravel (version — see
[Version resolution](#version-resolution)), Blade, Livewire, Inertia
(server-side package), and these known first-party Laravel packages:
Sanctum, Fortify, Jetstream, Breeze, Octane, Horizon, Telescope, Pulse,
Reverb, Scout, Cashier.

### Frontend

Node/package.json presence, package manager (npm/Yarn/pnpm, from which
lockfile is present), Vite, React, Vue, TypeScript, Tailwind, Inertia
client package (`@inertiajs/react`/`@inertiajs/vue3`/`@inertiajs/svelte`).

### Testing

Pest, PHPUnit, Playwright, Vitest, Jest, Cypress.

### Infrastructure

Docker (`Dockerfile`), Docker Compose (`docker-compose.*`/`compose.*`),
GitHub Actions (`.github/workflows/*.yml`), GitLab CI (`.gitlab-ci.yml`),
and three **hints** (deliberately weaker signals, not asserted as
certain): Redis usage, non-default queue connection, and scheduler
presence (`routes/console.php` mentioning `Schedule::`/`->schedule(`).

### Database

SQLite, MySQL, MariaDB, PostgreSQL, SQL Server — see
[Evidence strategy](#evidence-strategy) for why this is deliberately
narrow.

## Evidence strategy

| Signal                                               | Source(s)                                                                                                         | Notes                                                                                                                                                                                                                                                                                                                                                                                   |
| ---------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| PHP constraint                                       | `composer.json`: `require.php`                                                                                    | Constraint only — never an "installed" PHP version, since Discovery doesn't run PHP to check.                                                                                                                                                                                                                                                                                           |
| Laravel version                                      | `composer.lock` (`laravel/framework` entry) preferred; `composer.json`: `require.laravel/framework` as fallback   | See [Version resolution](#version-resolution).                                                                                                                                                                                                                                                                                                                                          |
| Blade                                                | `resources/views/**/*.blade.php` (bounded recursive scan)                                                         | File-based, not composer-based — a project can require `laravel/framework` without actually using Blade views.                                                                                                                                                                                                                                                                          |
| Livewire / Inertia (backend) / known packages        | `composer.json`: `require.<package>`                                                                              | `require` only, not `require-dev`.                                                                                                                                                                                                                                                                                                                                                      |
| React / Vue / Inertia client                         | `package.json`: `dependencies`/`devDependencies`                                                                  |                                                                                                                                                                                                                                                                                                                                                                                         |
| Vite / TypeScript / Tailwind                         | `package.json` dependency **or** a well-known config file (`vite.config.*`, `tsconfig.json`, `tailwind.config.*`) | Config file alone counts — some setups don't list the tool as an npm dependency.                                                                                                                                                                                                                                                                                                        |
| Package manager                                      | Lockfile presence: `pnpm-lock.yaml` > `yarn.lock` > `package-lock.json`                                           | First match wins; a project is assumed to use one primary manager.                                                                                                                                                                                                                                                                                                                      |
| Pest / PHPUnit                                       | `composer.json` (`require`/`require-dev`) **or** `pest.php`/`tests/Pest.php`/`phpunit.xml*`                       |                                                                                                                                                                                                                                                                                                                                                                                         |
| Playwright / Vitest / Jest / Cypress                 | `package.json` dependency **or** the tool's own config file                                                       |                                                                                                                                                                                                                                                                                                                                                                                         |
| Docker / Docker Compose / GitHub Actions / GitLab CI | File/glob presence only                                                                                           |                                                                                                                                                                                                                                                                                                                                                                                         |
| Redis / queue / scheduler hints                      | `.env.example` (`REDIS_`, non-`sync`/`database` `QUEUE_CONNECTION`) / `routes/console.php` text                   | Named "hints" deliberately — presence of a `REDIS_HOST` line doesn't prove Redis is load-bearing.                                                                                                                                                                                                                                                                                       |
| Database driver                                      | `.env.example`: `DB_CONNECTION=` (only)                                                                           | **Deliberately does not read `config/database.php`'s connection list** — Laravel's stock skeleton ships all five drivers' connection blocks in every app regardless of which one is actually used, so treating that file as evidence would make every Laravel project appear to use all five databases. See [ADR-0008](../architecture/decisions/ADR-0008-static-project-discovery.md). |

Every one of these is read via `ProjectFilesystem` (JSON via
`json_decode`, everything else as plain text) — never executed.

## Version resolution

- **With a lock file:** the installed version wins.
  `composer.lock`'s `laravel/framework` entry → `installed_version`; the
  `composer.json` constraint (if present) is still reported alongside it
  for context.
- **Without a lock file:** only the constraint is reported;
  `installed_version` stays `null`. Discovery never invents an installed
  version from a constraint.

The same rule is designed to extend to any future package version
resolution (frontend packages via a JS lock file, once that's needed).

## Limitations

- Multi-connection database setups (e.g. a project with both a primary
  MySQL connection and a reporting PostgreSQL connection) are not fully
  covered — only the `.env.example` default `DB_CONNECTION` is read.
- A project whose database choice lives only in a real `.env` with no
  corresponding `.env.example` entry reports `unknown`, not a guess.
- Redis/queue/scheduler signals are hints, not confirmations — they can
  under-detect (e.g. a scheduler defined via a custom bootstrap path
  instead of `routes/console.php`).
- No frontend package version resolution yet (no JS lock file is parsed
  for installed versions) — only the declared `package.json` range.
- Discovery does not attempt to distinguish a Laravel package required
  but never actually used from one that's load-bearing.

## No-code-execution guarantee: verification

`tests/Unit/Audit/Discovery/NoCodeExecutionTest.php` copies a fixture
(`tests/Fixtures/discovery/no-code-execution/`) whose `composer.json` and
`package.json` both declare scripts (`post-install-cmd`,
`post-update-cmd`, `postinstall`, `preinstall`, `test`) that would run
`touch SHOULD_NEVER_EXIST` if executed, into a throwaway temp directory,
runs `ProjectDiscovery` against it, and asserts the marker file was never
created — in the temp copy or, defensively, in the committed fixture
itself. This is the automated proof behind
[ADR-0008](../architecture/decisions/ADR-0008-static-project-discovery.md).

## CLI usage

```
php artisan laradogs:inspect {path} [--json]
```

- `path` — directory to inspect.
- `--json` — print the full `ProjectProfile` as JSON instead of the
  human-readable summary. The schema is exactly what `ProjectProfile` (and
  its nested value objects) serialize to via `JsonSerializable` — there is
  no separate CLI-only schema.

The command contains no detection logic — it calls `ProjectDiscovery`
exactly as any other caller would.

### Example (human-readable)

```
$ php artisan laradogs:inspect /path/to/some-laravel-app

Project: /path/to/some-laravel-app
Type: laravel

Backend
  PHP: detected (constraint: ^8.3)
  Composer: detected (composer.json)
  Composer lock: detected (composer.lock)
  Laravel: detected (installed: 13.4.2)
  Blade: detected (resources/views/**/*.blade.php)
  Livewire: not_detected
  Inertia (backend): detected (composer.json: require.inertiajs/inertia-laravel)
  ...

Frontend
  Node/npm: detected (package.json)
  Package manager: npm
  Vite: detected (package.json: devDependencies.vite)
  React: detected (package.json: dependencies.react)
  ...

Database
  No driver detected from static evidence.
```

### Example (`--json`, abridged)

```json
{
    "path": "/path/to/some-laravel-app",
    "status": "ok",
    "profile": {
        "project": { "path": "/path/to/some-laravel-app", "type": "laravel" },
        "backend": {
            "php": {
                "status": "detected",
                "constraint": "^8.3",
                "installed_version": null,
                "evidence": "composer.json: require.php"
            },
            "laravel": {
                "status": "detected",
                "constraint": "^13.0",
                "installed_version": "13.4.2",
                "evidence": "composer.lock: laravel/framework"
            }
        },
        "database": {
            "drivers": {
                "sqlite": {
                    "status": "unknown",
                    "evidence": "no .env.example present"
                },
                "...": "..."
            },
            "drivers_detected": []
        }
    }
}
```

A failed discovery (bad path) instead returns `{"path": ..., "status":
"path_not_found", "profile": null}` and the command exits non-zero.

## Fixtures

Synthetic, minimal fixtures under `tests/Fixtures/discovery/` back the
test suite — never a real project. See the Phase 1 report for the full
list and what each one exercises.
