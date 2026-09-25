# MCP / IDE Integration

**Status: Implemented (Phase 11).** Tool contract `schema_version: 1`,
server version `1.0.0`, transport **stdio**.

LaraDogs exposes its audit data to coding agents (Claude Code, Cursor,
VS Code, any MCP client) through a Model Context Protocol server. It is an
**interface**, not the Audit Core (see
[ADR-0002](../architecture/decisions/ADR-0002-application-architecture.md)):
nothing about running an audit depends on MCP being configured.

```
Claude Code / VS Code / Cursor / other MCP clients
                    | stdio (JSON-RPC, one process per client)
                    v
          php artisan laradogs:mcp        <- token from LARADOGS_MCP_TOKEN
                    |  McpAccess (token + CURRENT user role, every call)
                    v
      App\Mcp\Tools\*  (adapters: validate -> call -> shape -> sanitize)
                    |
                    v
   existing query / application services  (ProjectListQuery, ScanHistoryQuery,
   CurrentFindingsQuery, ProjectQualityGateQuery, RunProjectAudit::enqueue…)
```

The MCP layer is an **adapter**. It is not a scanner, not a rules engine, not
a second persistence layer and not a privileged backdoor: every tool goes
through the same query/application services the Dashboard and the CLI use,
and it re-applies the same authorization decisions.

Built on the official [`laravel/mcp`](https://github.com/laravel/mcp)
package (server, stdio transport, schema and testing API).

## Scope of V1

| Kind   | Tools                                                                                                                                                                                       |
| ------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Read   | `laradogs.list_projects`, `get_project`, `get_project_profile`, `get_project_source`, `list_scans`, `get_scan`, `list_findings`, `get_finding`, `get_quality_gate`, `get_scan_quality_gate` |
| Action | `laradogs.run_project_audit` (asynchronous only), `laradogs.get_audit_status`                                                                                                               |

Tools only — **no resources, no prompts** (smaller attack surface).

**Deliberately not in V1** (and not silently available through some other
tool): changing a finding's status (`update_finding_status` is deferred —
it needs a scope/actor/provenance design of its own), policy mutation, user
management, arbitrary SQL/filesystem/shell/Artisan/Git access, executing
the audited project's code, auto-fix, PR/issue creation, `git
checkout`/`fetch`/`pull`. Remediation belongs to Phase 12; the MCP server
supplies context and the _calling agent_ edits code.

## Transport: stdio

One canonical transport: the MCP client launches
`php artisan laradogs:mcp` and talks JSON-RPC over the process's
stdin/stdout.

- **No network listener.** No public unauthenticated HTTP endpoint, no new
  host port, nothing added to `docker-compose.yml`. The client already owns
  the process; its trust boundary is the operating-system user that started
  it plus the token below.
- **stdout carries the protocol only.** Banners, warnings, deprecations and
  diagnostics go to **stderr**. This is proven by a subprocess test that
  runs the real command and asserts that every stdout line is a valid
  JSON-RPC message (`tests/Feature/Mcp/McpStdioTest.php`).
- **EOF is a clean shutdown** (exit code `0`). A missing/invalid token at
  start exits non-zero with a message on stderr and **nothing** on stdout.
- Streamable HTTP is intentionally not offered: it would need a public,
  separately-hardened endpoint (TLS, origin checks, rate limiting) for no
  benefit to the local/IDE use case. It can be added later without changing
  the tool contract.

Only a manual `php artisan mcp:start laradogs` (the package's own command)
bypasses the startup token check — but **every tool still authenticates on
each call**, so it cannot be used to skip authorization.

## Authentication

MCP uses a **dedicated MCP token**, never a user's password or session.

- Format: `ldmcp_<public-id>_<secret>` — 16 hex characters of public id
  (indexed lookup) and a 256-bit (64 hex) secret.
- **Only a hash is stored** (SHA-256 of the secret; the secret has 256 bits
  of entropy, so a slow password hash adds nothing). The plaintext is shown
  **once**, at creation. Comparison is constant-time (`hash_equals`), and
  an unknown id is compared against a dummy hash so timing does not reveal
  which ids exist.
- Each token records `name`, owning `user`, `scope`, `last_used_at` (written
  at most once a minute) and `revoked_at`.
- The client supplies it through the **`LARADOGS_MCP_TOKEN` environment
  variable** of the launched process. It is never a tool argument, never
  logged, never echoed in an error and never returned by any tool.
- All failures return the same generic `unauthenticated` error (malformed,
  unknown, wrong secret, revoked, and owner deactivated are
  indistinguishable to the caller).
- **No default tokens**: the migration creates none.

### Managing tokens (CLI)

```bash
php artisan laradogs:mcp:token-create owner@example.com --name="Claude Code — laptop" --scope=read
php artisan laradogs:mcp:token-create owner@example.com --name="Claude Code — audits" --scope=audit
php artisan laradogs:mcp:token-list              # never prints secrets
php artisan laradogs:mcp:token-revoke <token-id> # effective on the very next request
```

There is deliberately no Dashboard UI for tokens in Phase 11.

## Authorization

Two independent gates, **both** evaluated on **every tool call** against the
**current** state of the database (a long-running MCP process never caches
an authorization decision):

1. **Token scope** — `read` (all read tools) or `audit` (read + `run_project_audit`).
2. **The owner's current role/activation** — inactive users are rejected;
   only **Owner/Admin** may queue audits; a `User`-role owner is read-only
   even with an `audit` token. Demoting or deactivating a user, or revoking
   a token, therefore takes effect on the next call, with no restart.

A token can never do more than its owner could do in the Dashboard, and an
`audit` token cannot even be issued to a `User`-role account. Owner identity
remains protected exactly as elsewhere (MCP has no user-management tool).
Everything runs through one authorizer (`App\Mcp\Auth\McpAccess`).

Scope creep is prevented structurally: the server only registers the tools
listed above, and a test asserts the exact tool list and that none of the
forbidden capability names exist.

## Tool contract (`schema_version: 1`)

Every structured result contains `schema_version: 1`. Additive fields may
appear inside version 1; any removal/rename/meaning change bumps the version.

Conventions common to all tools:

- **Stable public ids only.** Projects, scans and findings are addressed by
  their 26-character ULID public id. Internal numeric ids never appear.
- **Project-relative paths only.** No absolute host path is ever returned
  (including in `get_project_profile` — the profile's `project.path` is
  removed — and in messages/snippets, where absolute paths are masked).
- **Bounded lists.** `limit` default **25**, maximum **100**, `page` is
  1-based (bounded); every list returns
  `pagination: {page, limit, total, has_more, returned}`. There is no "return
  everything" option.
- **Static descriptions.** Tool names, titles, descriptions, schemas and the
  server instructions are constants written by LaraDogs — never derived from
  a project, finding or file.
- **Unknown arguments are rejected** (`invalid_arguments`), not ignored.
- **Read tools are annotated read-only**; `run_project_audit` is the only
  tool that is not.

### Read tools

| Tool                             | Arguments                                                                                                            | Returns                                                                                                                                                                                                                  |
| -------------------------------- | -------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `laradogs.list_projects`         | `limit?`, `page?`                                                                                                    | Project summaries (framework, open-finding count, last scan, quality-gate status) + pagination. Computed with aggregate queries (no N+1, no Git subprocess per row).                                                     |
| `laradogs.get_project`           | `project_id`                                                                                                         | One project summary.                                                                                                                                                                                                     |
| `laradogs.get_project_profile`   | `project_id`, `scan_id?`                                                                                             | Technology profile snapshot (latest, or the given scan's).                                                                                                                                                               |
| `laradogs.get_project_source`    | `project_id`                                                                                                         | Source state with Phase 9 semantics: the **current** repository state versus the state of the **last audited** scan, and their consistency. Read-only; may run bounded local Git metadata reads for this single project. |
| `laradogs.list_scans`            | `project_id`, `status?`, `origin?`, `limit?`, `page?`                                                                | Scan summaries + pagination.                                                                                                                                                                                             |
| `laradogs.get_scan`              | `scan_id`                                                                                                            | Scan detail incl. source provenance, execution summary and gate summary.                                                                                                                                                 |
| `laradogs.list_findings`         | `project_id`, `status[]?`, `severity[]?`, `category[]?`, `confidence[]?`, `analyzer?`, `rule_id?`, `limit?`, `page?` | Current findings (summary + latest project-relative location) + pagination.                                                                                                                                              |
| `laradogs.get_finding`           | `finding_id`                                                                                                         | Finding detail: redacted evidence, bounded occurrences (max 5), bounded status history (max 10, **without actor identity**), references (max 10).                                                                        |
| `laradogs.get_quality_gate`      | `project_id`                                                                                                         | The project's gate policy (or `enabled: false`) and latest result.                                                                                                                                                       |
| `laradogs.get_scan_quality_gate` | `scan_id`                                                                                                            | The **persisted** gate result of that scan (`evaluated` flag). **Never re-evaluated**: history is immutable even if the policy changed since.                                                                            |

### Action tools

**`laradogs.run_project_audit`** — `project_id`. Requires `audit` scope and
an Owner/Admin owner. It calls the existing `RunProjectAudit::enqueue()`
(the same path as the Dashboard's queued audit), so the existing
**one-active-scan-per-project** mutex applies, and dispatches the existing
`RunProjectAuditJob`. It **returns immediately** with
`{project_id, scan_id, status: "queued"}` — an audit is never executed
inside the MCP request. If a scan is already active the result is the typed
error `audit_already_running` with `details.scan_id`. It cannot pass a path,
command, analyzer selection, or ref; it audits the project as registered
and it never runs `git checkout/fetch/pull`.

**`laradogs.get_audit_status`** — `scan_id`. Returns the scan status
(`queued`/`running`/terminal), timestamps, duration, source provenance and
the quality-gate summary once available. Clients poll this; there is no
streaming.

### Error contract

Failures are returned as a tool error (`isError: true`) whose structured
content is:

```json
{
    "schema_version": 1,
    "error": { "code": "project_not_found", "message": "…", "details": {} }
}
```

| Code                      | Meaning                                                                                                                                             |
| ------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| `unauthenticated`         | Missing/invalid/revoked token or deactivated owner (always generic).                                                                                |
| `forbidden`               | Authenticated, but the token scope or the owner's role forbids it.                                                                                  |
| `invalid_arguments`       | Unknown/malformed argument or out-of-range pagination.                                                                                              |
| `project_not_found`       | No such project public id.                                                                                                                          |
| `scan_not_found`          | No such scan public id.                                                                                                                             |
| `finding_not_found`       | No such finding public id.                                                                                                                          |
| `audit_already_running`   | A scan is already active for this project (`details.scan_id`).                                                                                      |
| `temporarily_unavailable` | Transient condition; retry later.                                                                                                                   |
| `internal_error`          | Unexpected failure. **Generic message** — no stack trace, SQL, path or exception text is ever returned; the real exception is reported server-side. |

### Evidence redaction and untrusted content

Finding titles, messages, snippets and file paths originate in the
**audited project** — potentially hostile text. Two rules follow:

1. **Redaction is enforced again at the MCP boundary** (`McpSanitizer`),
   on top of the redaction applied at ingestion: the evidence redactor is
   re-applied, plus patterns for PEM private keys, `Bearer` tokens,
   GitHub/OpenAI/Slack/Google/JWT-style secrets and `ldmcp_` tokens;
   absolute host paths are masked, control characters removed, and every
   field length-bounded.
2. **Content is data, not instructions.** Payloads that carry project text
   include `content_trust: "untrusted_source_data"`, and the server's
   instructions say so. LaraDogs' _own_ strings (tool descriptions, errors,
   instructions) are static. A test proves that a finding whose title says
   "ignore previous instructions…" surfaces only inside the finding data
   fields and never in tool metadata or errors. Clients should still treat
   these fields as untrusted input.

## Long-running process behavior

`laradogs:mcp` lives as long as the client keeps stdin open.

- **Stateless per request:** nothing about a caller, project or result is
  kept between calls; the token is re-read from the environment and
  re-authenticated on each call (the same bounded cost as one indexed
  lookup), so revocation/role changes apply immediately.
- **Bounded memory:** every list is capped (100 rows), evidence fields are
  length-bounded, occurrences/history/references are capped; results are
  built per request and released.
- **Database:** the connection is the framework's normal lazy connection; a
  database outage yields `temporarily_unavailable`/`internal_error` for that
  call, and the process recovers on the next.
- **Shutdown:** EOF on stdin ends the process with exit code `0`; the client
  closing the pipe is the supported way to stop it.

## Configuring a client

### Claude Code — local checkout

```json
{
    "mcpServers": {
        "laradogs": {
            "command": "php",
            "args": ["artisan", "laradogs:mcp"],
            "cwd": "/path/to/LaraDogs",
            "env": { "LARADOGS_MCP_TOKEN": "ldmcp_<public-id>_<secret>" }
        }
    }
}
```

or `claude mcp add laradogs --env LARADOGS_MCP_TOKEN=<your-token> -- php
/path/to/LaraDogs/artisan laradogs:mcp`. Replace the placeholder with a token
from `laradogs:mcp:token-create`; never commit the real value (keep the file
outside the repository or use your client's secret/env mechanism).

### Claude Code — Docker Compose stack

```json
{
    "mcpServers": {
        "laradogs": {
            "command": "docker",
            "args": [
                "compose",
                "-f",
                "/opt/laradogs/docker-compose.yml",
                "exec",
                "-T",
                "-e",
                "LARADOGS_MCP_TOKEN",
                "app",
                "php",
                "artisan",
                "laradogs:mcp"
            ],
            "env": { "LARADOGS_MCP_TOKEN": "ldmcp_<public-id>_<secret>" }
        }
    }
}
```

- **`-T` is required** (no pseudo-TTY, or the JSON-RPC stream is corrupted).
- `-e LARADOGS_MCP_TOKEN` (no value) forwards the variable from the client's
  environment into the container; the token never appears in the command line.
- No port is published: this reuses `docker exec` into the already-running
  `app` container, exactly like `laradogs:ci:audit`. Rebuild the image after
  upgrading so `laravel/mcp` is present, and run the migration
  (`mcp_tokens`).
- Note the server sees the project paths **as mounted in the container**;
  clients only ever receive project-relative paths regardless.

### Other clients (Cursor, VS Code, …)

**Tested:** Claude Code (headless `claude -p --mcp-config`, against SQLite-
and MySQL 8.4-backed installs: initialize, tools/list, all read tools and
`run_project_audit` + `get_audit_status`).

**Not yet validated:** Cursor, VS Code and any other MCP client. The server
follows the MCP stdio transport and protocol versions supported by
`laravel/mcp`, so a conforming client _should_ be able to spawn it with the
same command/args/env — but that is a protocol expectation, not a tested
claim.

**Database support:** the `mcp_tokens` migration is portable and was
exercised on SQLite and MySQL 8.4. Other engines the project's persistence
layer targets have not been run against this phase.

## Actor / provenance

Audits queued through MCP are recorded with the new
`ScanOrigin::Mcp` (label "MCP"), distinct from `Manual` (Dashboard click),
`Cli` and `Scheduled`, and are attributed to the token's owner
(`initiated_by_user_id`). Rationale: an agent acting on someone's behalf is
a different provenance from a person clicking a button, and history/filters
should be able to tell them apart. Adding an origin is purely additive (the
column is a string; no enum type, no data migration, portable across
SQLite/MySQL). User emails are never exposed through MCP.

## Security summary

- No plaintext token storage, no default tokens, no token in logs/errors.
- Authorization re-evaluated per call from the current user; Owner/Admin
  only for audits; no user-management surface.
- No filesystem/shell/SQL/Git/Artisan primitive is exposed; tool arguments
  are validated IDs and enums, never paths or commands.
- Absolute paths and secrets are masked at the boundary; identities of
  finding-status actors are not exposed.
- Prompt-injection surface: static tool metadata; project text is labeled
  and structurally confined to data fields.
- Audit is asynchronous only and goes through the existing mutex/queue.
- Full model: [`../architecture/security-model.md`](../architecture/security-model.md).

## Known limitations

- stdio only; a remote/hosted MCP endpoint would need its own OAuth/TLS
  design (see ADR-0006's note on keeping the door open for OAuth/OIDC).
- The token is per-process (environment); token expiry (`expires_at`) and
  finer scopes than `read`/`audit` are not implemented.
- No `update_finding_status` (deferred); no resources/prompts; no
  streaming/progress notifications (poll `get_audit_status`).
- No Dashboard UI for token management.
- Only Claude Code was used as a real client in Phase 11.

## Relationship to the roadmap

Phase 11 delivers **read access + controlled audit queuing**. Remediation
(status changes, fix workflows) is **Phase 12** and is not started; the
MCP contract above is designed so those tools can be added under
`schema_version: 1` without changing existing ones. The older
[ADR-0006](../architecture/decisions/ADR-0006-mcp-security-model.md) records
the original binding constraints (per-client credentials, one-time display,
hashed storage, no default code editing); Phase 11 satisfies them with the
simplified `read`/`audit` scopes above.
