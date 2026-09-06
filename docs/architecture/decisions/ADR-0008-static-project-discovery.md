# ADR-0008: Static, Evidence-Based Project Discovery Without Executing Target Code

## Status

Accepted (Phase 1).

## Context

Project Discovery (Phase 1) is the first LaraDogs feature that touches a
directory it does not control: the project being inspected. Per
[the security model](../security-model.md) and
[ADR-0004](ADR-0004-scanner-execution-strategy.md), analyzed code must be
treated as **untrusted** — but ADR-0004 was written for Phase 4's scanner
orchestration (shelling out to `composer audit`, PHPStan, etc.) and doesn't
by itself answer the narrower question Phase 1 actually faces: how does
discovery learn what stack a project uses _at all_, before any scanner
runs?

The naive approaches are all unacceptable for code whose author is
unknown and whose intent may be hostile:

- Running `composer install`/`npm install` to introspect the resolved
  dependency tree would execute the target's Composer/npm scripts
  (`post-install-cmd`, `postinstall`, etc.) — a well-known supply-chain
  attack vector.
- Running `php artisan about` (or any `artisan` command) would boot the
  target's own service providers, config, and autoloaded classes.
- `include`/`require`-ing `config/database.php` or any other target PHP
  file to introspect its return value would execute arbitrary PHP that
  originates in the untrusted repository — indistinguishable, from a
  security standpoint, from running the project itself.

At the same time, Discovery's whole job is figuring out what's really
there — which means it cannot simply trust every manifest file at face
value either. Laravel's own stock `config/database.php` ships connection
blocks for `sqlite`/`mysql`/`mariadb`/`pgsql`/`sqlsrv` in _every_ Laravel
app regardless of which one a given project actually uses; conflating
"the framework supports X" with "this project uses X" would make every
detected Laravel project falsely report five configured databases.

## Decision

- **Project Discovery never executes anything that originates in the
  analyzed project.** No `composer`/`npm`/`artisan`/`vendor` binary,
  script, `Makefile` target, Docker build, or GitHub Actions workflow is
  ever invoked. No file from the target is ever `include`d, `require`d, or
  `eval`'d — PHP files (e.g. `routes/console.php`) that are inspected at
  all are read as **plain text** and pattern-matched, never parsed as
  code.
- **All detection is evidence-based, from a fixed allow-list of file
  types:** JSON manifests (`composer.json`, `composer.lock`,
  `package.json`, lockfiles) parsed with `json_decode`, plus a small set
  of files read as plain text for existence or simple regex checks
  (`.env.example`, `vite.config.*`, `tsconfig.json`,
  `routes/console.php`, CI/Docker file presence). See
  [`docs/auditing/project-discovery.md`](../../auditing/project-discovery.md)
  for the exact list per capability.
- **Framework-level support is not evidence of project-level usage.**
  Detections that would otherwise be inferred from a file that Laravel's
  own skeleton ships unconditionally (`config/database.php`'s connection
  blocks being the concrete case that motivated this ADR) are not trusted
  as evidence. Database driver detection instead relies solely on
  `.env.example`'s explicit `DB_CONNECTION=` line — the project's own
  declared default — and reports `unknown` (not `not_detected`) when that
  line isn't present, rather than guessing.
- **Every detection carries its own status** — `detected`,
  `not_detected`, `unknown`, `invalid`, or `unsupported` — so "we have no
  evidence" is never conflated with "we have evidence of absence," and a
  malformed manifest (`invalid`) is never conflated with a missing one
  (`unknown`/`not_detected`).
- **The project's real `.env` is never read.** Only `.env.example` is
  used as a hint source; secrets are never a Discovery concern because
  Discovery never has a path to them in the first place.
- **Filesystem access is bounded**, not just execution: reads are capped
  by size, recursive lookups are capped in depth and entries visited, and
  every resolved path is verified (via `realpath()`) to stay inside the
  inspected root — rejecting both path traversal and a symlink planted
  inside the project that points outside it. See
  `app/Audit/Discovery/Filesystem/ProjectFilesystem.php`.

## Consequences

- Discovery is necessarily incomplete compared to "actually installing
  and introspecting the project" — e.g. it cannot see a dependency's
  _transitively resolved_ version the way a real `composer install`
  would beyond what `composer.lock` already records, and it cannot detect
  a database driver a project selects only via a real (gitignored) `.env`
  with no corresponding `.env.example` entry. This is an accepted
  trade-off: safety over completeness. Gaps are reported as `unknown`,
  not silently guessed.
- Every future Discovery capability (new frameworks, new scanners'
  presence, new package ecosystems) must be added as another static,
  evidence-based check against an explicit file allow-list — never as
  "just run the tool and see what it reports," which is Phase 4's
  concern (scanner execution) under its own isolation model, not
  Discovery's.
- This constraint is verified by an automated test
  (`tests/Unit/Audit/Discovery/NoCodeExecutionTest.php`) that runs
  Discovery against a fixture whose `composer.json`/`package.json`
  scripts would create a marker file if executed, and asserts the marker
  is never created.
