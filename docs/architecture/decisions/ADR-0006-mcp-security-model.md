# ADR-0006: MCP Security Model

## Status

Proposed — no MCP server exists yet (Phase 9+). This ADR records the
security constraints that must hold once it does, so the credential model
isn't designed reactively after a real vulnerability report.

## Context

MCP is how coding agents (Claude Code, Cursor, VS Code, etc.) will read
LaraDogs findings and remediation context. The brief is explicit that even
**local, single-user installs must be designed securely** — MCP access is
still a credentialed API surface, not an implicitly-trusted local pipe, and
the LaraDogs MCP server must never edit arbitrary code by default: it
supplies context, the calling coding agent makes the edits.

## Decision

- MCP access requires a **per-client credential** (e.g. "Claude Code —
  MacBook", "CI Runner"), not a single shared token. Each credential
  carries `owner`, `name`, `scopes`, `created_at`, `last_used_at`,
  `expires_at`, `revoked_at`.
- Credential **secrets are cryptographically random, shown once at
  creation, and never stored recoverable in plaintext** (hashed at rest,
  the same way a password would be — not merely encrypted-and-decryptable).
- Scopes are enforced per-credential (conceptually: `projects:read`,
  `scans:read`, `scans:run`, `findings:read`, `findings:update`,
  `reports:read`, `system:read`, `admin`). A credential scoped to
  `findings:read` must not be able to trigger `scans:run`.
- The MCP server exposes **findings and remediation context**; it does not
  itself modify the analyzed project's files. "Investigate SEC-0042, fix
  it, run tests" is a workflow the _calling agent_ carries out using the
  context LaraDogs returns — LaraDogs is not the thing making the edit.
- The credential model must not preclude **OAuth/OIDC** later for larger
  deployments — i.e. don't hardcode assumptions (single-tenant secret
  table with no issuer/audience concept) that would force a breaking
  rewrite to add SSO. Full OAuth is explicitly not built in this phase.

## Consequences

- Phase 10 (Authentication / MCP Credentials) must implement token hashing
  and scope checks as a first-class authorization layer, not an
  afterthought bolted onto existing Dashboard auth.
- Any MCP tool that could mutate state (e.g. changing a finding's status)
  must check scope, not just "is this request authenticated at all."
- This ADR does not pick the concrete token format, hashing algorithm, or
  storage table — those are Phase 10 implementation details. What's locked
  in now is: per-client credentials, one-time secret display, scoped
  access, and no default code-editing capability inside the MCP server
  itself.
