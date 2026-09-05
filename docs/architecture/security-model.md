# Security Model

LaraDogs is a security tool, so its own security posture is architecture,
not an afterthought. This document distinguishes what's actually true today
(Phase 0) from what's a binding future constraint and from what's simply
not built yet.

## Core principle: analyzed code is untrusted

Once the Audit Core exists (Phase 2+), it will point at arbitrary
third-party Laravel repositories. **Never assume it's safe to execute
anything originating from the analyzed project** — its test suite, build
scripts, PHPStan/ESLint/Semgrep config, Composer/NPM scripts, none of it.
This drives [ADR-0004](decisions/ADR-0004-scanner-execution-strategy.md)
(scanner isolation: timeouts, resource limits, non-root, no path traversal
outside the project root) and is the reason scanner sandboxing is called
out as a Phase 4 design requirement rather than "just shell out to the
tool."

Nothing in the current codebase executes analyzed-project code — there is
no Audit Core yet — so this principle is not yet tested against real
implementation, only recorded so Phase 4 doesn't skip it.

## Secrets

- **Never persist a detected secret in full.** A future finding whose
  evidence is e.g. an AWS key must store a redacted form
  (`AKIA************92H`), not the raw value, even in the database. This is
  a Phase 3+ constraint (no secret-detection exists yet), recorded in
  [ADR-0003](decisions/ADR-0003-finding-domain-model.md).
- **Today's actual secrets** are ordinary Laravel `.env` values (`APP_KEY`,
  and later any scanner/DB credentials). `.env` is gitignored (verified:
  `git check-ignore -v .env` reports it ignored); only `.env.example` (no
  real values) is committed.

## Authentication (today)

The React starter kit's Fortify-based authentication ships as-is:
login, registration, password reset, email verification, 2FA (TOTP),
passkeys. This is **starter-kit scaffolding, not a LaraDogs-designed auth
model**, and carries a known gap worth flagging explicitly:

> **Known limitation:** public self-registration (`/register`) is enabled
> by default, as it is for any fresh `laravel new --react` app. For a
> self-hosted security tool, an install that's reachable on a network
> likely wants registration disabled or invite-only before real findings
> (including redacted secrets, file paths, code snippets) are stored
> behind it. This is deliberately **not changed in Phase 0** — hardening
> the auth model is explicit scope for Phase 10 (Authentication / MCP
> Credentials), once there's something worth protecting. Anyone deploying
> this Phase 0 foundation beyond local development should disable
> registration first (see Fortify's `Features::registration()` toggle in
> `config/fortify.php`).

## MCP (planned, not built)

See [ADR-0006](decisions/ADR-0006-mcp-security-model.md) for the full
model: per-client credentials, one-time secret display, hashed storage,
scopes, no default code-editing capability. None of this exists yet —
there is no MCP server in this repository.

## What was verified in Phase 0

- `.env`, `.env.backup`, `.env.production`, `database/*.sqlite`,
  `storage/*.key`, `vendor/`, `node_modules/`, `auth.json` are all
  gitignored (starter-kit defaults, spot-checked with `git status` /
  `git check-ignore`).
- `composer audit` and `npm audit` both report **zero known
  vulnerabilities** in the dependency set installed during bootstrap
  (see [`../development/testing.md`](../development/testing.md) for the
  exact commands and output).
- The Docker image runs the application as a **non-root** user
  (`laradogs`, uid 1000), not root — see the `Dockerfile`.
- `APP_DEBUG=true` in `.env`/`.env.example` is correct for local
  development (Laravel's default) but **must be `false` in any
  non-local deployment** — not enforced by code today, just documented
  here and in `.env.example`'s comments.

## What's explicitly deferred

- RBAC beyond Laravel's default auth (no roles/permissions concept exists).
- Rate limiting beyond Fortify's default login throttling.
- Audit logging of administrative actions.
- Scanner sandbox/isolation implementation (design constraint recorded now,
  code in Phase 4).
- Read-only project mounts for scanner execution (relevant once there's a
  scanner to run against a mounted, untrusted project — not applicable to
  LaraDogs' own container today, which only runs LaraDogs' own trusted
  code).
- Security headers / CSP hardening beyond Laravel's defaults.
