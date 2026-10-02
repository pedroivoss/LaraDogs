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
> the auth model is explicit scope for the not-yet-placed "Authentication"
> item (MCP credentials shipped in Phase 11), once there's something worth protecting. Anyone deploying
> this Phase 0 foundation beyond local development should disable
> registration first (see Fortify's `Features::registration()` toggle in
> `config/fortify.php`).

## MCP (Phase 11)

The MCP server ([`../integrations/mcp.md`](../integrations/mcp.md)) honors
the constraints recorded in
[ADR-0006](decisions/ADR-0006-mcp-security-model.md) (per-client credentials,
one-time secret display, hashed storage, scopes, no default code-editing
capability). Security properties, all covered by tests:

- **Transport:** stdio only. No public/unauthenticated HTTP endpoint, no new
  host port. stdout is protocol-only.
- **Credential:** dedicated `ldmcp_<id>_<secret>` token (256-bit secret),
  SHA-256 hash at rest, constant-time comparison, shown once, revocable,
  never logged; one generic `unauthenticated` error for every failure mode.
- **Authorization:** token scope (`read`/`audit`) AND the owner's _current_
  role/activation, evaluated on every call; only Owner/Admin may queue audits.
- **Surface:** thirteen fixed tools (Phase 12 added one read-only remediation-guidance tool); no filesystem/shell/SQL/Git/Artisan
  primitive, no target-code execution, no remediation. Audits are
  asynchronous, through the existing mutex.
- **Data:** stable public ids, project-relative paths only, bounded lists,
  evidence re-redacted at the boundary, no user identities, generic
  `internal_error`.
- **Prompt injection:** tool metadata is static; project-derived text is
  confined to data fields and labeled `untrusted_source_data`.

## Remediation guidance (Phase 12)

Remediation ([`../remediation/README.md`](../remediation/README.md)) is
**deterministic guidance only**. Security properties, covered by tests:

- **No target mutation, no execution:** the remediation namespace, its MCP
  tool and CLI command contain no file-write, process, Git-write, network or
  persistence-write primitive (parsed-token architectural guard); the planner
  is pure (no database, models, Git, container or config). Package-manager
  upgrades, target tests and target commands are never run.
- **No AI:** no model dependency or HTTP client; nothing is sent anywhere.
- **Untrusted evidence stays data:** finding text is sanitized by the single
  shared `OutputSanitizer` (secrets redacted, host paths masked, control
  characters stripped, bounded), never evaluated or interpolated into a
  shell/template, and confined to `finding`/`evidence` fields tagged
  `untrusted_source_data`. The persisted `recommendation` is never trusted for
  guidance; guidance comes only from LaraDogs constants for the bundled rules.
- **Reference safety:** `https` only; `javascript:`, `data:`, `file:`, `http:`,
  protocol-relative and credentialed URLs dropped; never fetched. The Dashboard
  also refuses non-https links and uses `rel="noopener noreferrer"`.
- **Authorization unchanged:** MCP `get_finding_remediation` needs only a valid
  read-scope token (the same semantics as `get_finding`); no new scope, no
  write capability. The Dashboard route keeps its existing authentication.

**Output/privacy hardening (Phase 12.1).** Two corrections, presentation-layer
only (no domain/history change):

- **One public-reference policy.** `App\Audit\Remediation\SafeReference` is
  the single canonical filter (`https` only, valid host, no credentials, no
  control characters, bounded length/count, never fetched) used by the
  remediation plan, `laradogs.get_finding` and the Dashboard alike — never a
  second, weaker sanitizer. Finding Detail no longer sends the raw, persisted
  `finding.references` to the browser at all (it was an unused, unvalidated
  prop); only the already-filtered `remediation.references` reaches the page.
- **Owner identity in finding status history.** `status_history.actor_identifier`
  is compared against the _current_ Owner's email (single-Owner invariant) and
  withheld — `null` with a neutral `actor_label: "Privileged user"` — from any
  viewer who is not the Owner. Every other actor (Admin, User, System) remains
  visible exactly as before; internal persistence (`finding_status_histories`)
  is untouched. This matches the Owner-invisibility invariant
  `App\Http\Controllers\Settings\UsersController` already establishes for user
  management. MCP already withheld all actor identity unconditionally
  (Phase 11) and is unaffected.

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
