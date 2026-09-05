# MCP Integration

**Status: Planned** (Phase 9 for the server, Phase 10 for credentials/auth).
No MCP server exists in this repository yet.

## What it's for

MCP is how coding agents (Claude Code, Cursor, VS Code, and others) will
read LaraDogs findings and act on them. It is an **interface**, not the
Audit Core — see
[ADR-0002](../architecture/decisions/ADR-0002-application-architecture.md).
Nothing about the Audit Core's ability to run an audit depends on MCP
existing or being configured.

```
Claude Code / VS Code / Cursor / other agents
                         |
                         v
                     LaraDogs MCP
                         |
                         v
                      Findings
```

## Conceptual tool surface (not final)

`get_capabilities`, `inspect_project`, `run_scan`, `get_scans`,
`get_findings`, `get_finding`, `compare_scans`, `get_remediation_context`,
`generate_report` — these names describe the _kind_ of operations
expected, not a committed API. The actual MCP tool contracts will be
designed in Phase 9 against a real Finding/Scan implementation, not
against this list.

## Security model

See [ADR-0006](../architecture/decisions/ADR-0006-mcp-security-model.md)
for the binding constraints: per-client credentials with scopes, one-time
secret display, hashed storage, and — critically — **the MCP server does
not edit arbitrary project code by default**. An agent might be told
"Investigate SEC-0042. If confirmed, fix it and run the tests" — LaraDogs
MCP supplies the investigation context (the finding, its evidence, related
code); the calling coding agent is what makes the edit and runs the tests.

## Why this matters for Phase 0

Nothing in Phase 0 implements MCP. This document exists so the eventual
implementation starts from an agreed security posture (ADR-0006) instead
of exposing findings over MCP first and retrofitting scopes/credentials
after the fact.
