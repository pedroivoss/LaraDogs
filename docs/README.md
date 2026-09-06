# LaraDogs Documentation

This is the documentation index for LaraDogs. It is organized so that
"how it's built" (architecture), "how to work on it" (development), "how
auditing will work" (auditing), and "how it connects to other tools"
(integrations) are separate concerns.

**Status: Early Development.** Most of what LaraDogs is _for_ — scanners,
findings, history, the dashboard, MCP — does not exist in code yet. See
[`roadmap/phases.md`](roadmap/phases.md) for what phase we're in and
[`../README.md`](../README.md) for the Implemented/Planned split.

## Architecture

- [`architecture/overview.md`](architecture/overview.md) — the Audit Core
  vs. interfaces shape, and what actually exists today.
- [`architecture/components.md`](architecture/components.md) — the current
  Laravel application's moving parts.
- [`architecture/data-flow.md`](architecture/data-flow.md) — how a request
  flows through the app today, and how an audit run is intended to flow
  once the Audit Core exists.
- [`architecture/security-model.md`](architecture/security-model.md) —
  what's protected today, what's explicitly deferred, and the non-negotiable
  constraints (untrusted analyzed code, secret redaction, MCP scoping).
- [`architecture/decisions/`](architecture/decisions/) — ADRs. Read these
  for _why_, not just _what_.

## Development

- [`development/setup.md`](development/setup.md) — local setup without
  Docker.
- [`development/docker.md`](development/docker.md) — local setup with
  Docker Compose.
- [`development/testing.md`](development/testing.md) — running the test
  suite, and why Pest.
- [`development/conventions.md`](development/conventions.md) — code style,
  linting, static analysis.

## Auditing

- [`auditing/project-discovery.md`](auditing/project-discovery.md) —
  **Implemented (Phase 1).** Static, evidence-based stack detection —
  what it detects, its security model, and CLI usage. Not auditing: no
  scanners run, no `Finding` is produced.
- [`auditing/audit-engine.md`](auditing/audit-engine.md) — **Implemented
  (Phase 2), foundation only.** The Analyzer contract, applicability vs.
  availability, planning, execution, and result normalization — exercised
  with synthetic analyzers. No real scanner integration exists yet.
- The rest of `auditing/` describes the **planned** domain model (Phase
  3+) that the engine will feed into. None of it is implemented yet — it
  exists so that Phase 3+ work has an agreed target instead of improvising
  mid-implementation.
    - [`auditing/overview.md`](auditing/overview.md)
    - [`auditing/findings.md`](auditing/findings.md)
    - [`auditing/severity.md`](auditing/severity.md)
    - [`auditing/confidence.md`](auditing/confidence.md)
    - [`auditing/suppressions.md`](auditing/suppressions.md)

## Integrations

- [`integrations/mcp.md`](integrations/mcp.md) — the MCP interface's
  intended shape and security model (Phase 9-10, not built yet).

## Roadmap

- [`roadmap/roadmap.md`](roadmap/roadmap.md) — phase list and current
  position.
- [`roadmap/phases.md`](roadmap/phases.md) — what "done" means for each
  phase, and deferred-item notes captured during Phase 0.
