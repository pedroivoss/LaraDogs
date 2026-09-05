# ADR-0001: Bootstrap Stack Selection

## Status

Accepted (Phase 0)

## Context

LaraDogs needs a concrete, working foundation before any audit-domain code
can be written. The product brief asks for Laravel + React/Inertia, SQLite
for the "Personal" profile, and either Pest or PHPUnit — "determine based on
the current ecosystem."

At the time of this decision (September 2026):

- Laravel 13 is the current stable major version (framework `v13.30.1` at
  install time) and requires **PHP 8.3+** (verified against
  `laravel.com/framework/docs/13.x/releases.md`).
- The local development machine has PHP 8.3.12, which satisfies the
  minimum but not the PHP 8.4 required by Pest 5.x.
- Laravel's official starter kits (React, Vue, Svelte, Livewire) are the
  framework maintainers' recommended head start for Inertia-based
  applications, and each ships both backend auth scaffolding (via Fortify)
  and a modern frontend toolchain (Vite, Tailwind 4, shadcn/ui) already
  wired together and tested against the current framework version.
- Both Pest and PHPUnit ship "out of the box" per Laravel's testing docs;
  the installer lets either be selected explicitly.

## Decision

- **Laravel 13.x** (installed via `laravel new`, not hand-assembled).
- **Official React starter kit** (`--react`), which brings Inertia 3,
  React 19, TypeScript, Tailwind 4, and shadcn/ui, plus Fortify-based
  authentication (login, registration, password reset, email verification,
  2FA, passkeys). This is scaffolding LaraDogs' own dashboard will build on
  in later phases — it is not audit-domain code.
- **Pest** as the testing framework. It is the default recommendation for
  new Laravel starter-kit projects in the current ecosystem and all code
  samples across Laravel's own docs lead with the Pest tab. Composer
  resolved `pestphp/pest ^4.7` instead of the newest `^5.1` because the
  latter requires PHP 8.4, which this environment does not have — this is
  expected, documented behavior, not a workaround.
- **SQLite** as the default database (`--database=sqlite`), matching the
  "Personal" deployment profile described in the product brief. PostgreSQL
  is deferred to the "Server" profile (see
  [ADR-0005](ADR-0005-persistence-and-deployment-profiles.md)).
- **PHP 8.3** as the floor. Laravel 13 supports 8.3–8.5; 8.3 was chosen
  because it's what's actually installed and verified working, not
  guessed. Contributors on 8.4/8.5 are expected to work without changes.
- Laravel Boost (an AI-agent developer aid) was **not** installed
  (`--no-boost`). It's an optional dev-time convenience unrelated to the
  audit product itself; adding it is left to a future, explicit decision
  rather than bundled into the bootstrap.

## Consequences

- The generated app carries starter-kit features LaraDogs does not need
  yet as a security product (e.g. public self-registration, WorkOS
  variant not used, two-factor auth, passkeys). These are tracked as a
  known limitation in `docs/architecture/security-model.md` and as a
  Phase 10 concern (authentication hardening / MCP credentials), not
  fixed now — Phase 0's job is a working foundation, not a hardened one.
- Because Pest resolved to `^4.7` rather than `^5.x`, upgrading the
  contributor/CI environment to PHP 8.4+ later will allow (but not
  require) a Pest major upgrade. This should be a deliberate, tested
  change, not incidental to a `composer update`.
- The installer generated a malformed `APP_URL` value
  (`http://localhost:8000:8000`) in `.env` due to what appears to be a bug
  in `laravel/installer` v5.25.0's URL-building logic — `.env.example` was
  unaffected. This was corrected by hand during bootstrap; it is called
  out here so it isn't mistaken for an intentional configuration choice.
