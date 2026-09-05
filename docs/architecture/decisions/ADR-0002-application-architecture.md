# ADR-0002: Application Architecture — Audit Core vs. Interfaces

## Status

Accepted (Phase 0) — establishes intent; not yet enforced by code structure
beyond a standard Laravel application skeleton.

## Context

LaraDogs' value has to survive the churn of its own interfaces. CLIs get
rewritten, dashboards get redesigned, MCP tool shapes will change as the
protocol and client agents mature. If detection, normalization, and
correlation logic lives inside a controller or a CLI command, every
interface reimplements (and subtly diverges from) the same logic.

The product brief is explicit about this shape:

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
```

## Decision

- The **Audit Core** is the product. It must be able to run a full audit
  **locally, deterministically, without any LLM/API key/network call to an
  AI provider**. This is a hard constraint, not an aspiration: no audit
  rule or scanner-orchestration code may have a runtime dependency on
  OpenAI, Anthropic, Gemini, or any other LLM SDK.
- CLI, MCP, Web Dashboard, and CI/CD integration are **adapters**. None of
  them may be required for the Audit Core to function. Concretely, this
  means audit logic must be callable as plain PHP (Artisan commands,
  service classes, jobs) without going through HTTP or a controller.
- AI is an **optional integration**, primarily through MCP, layered
  strictly on top of the Findings produced by the deterministic core. An
  agent can query and act on findings; it cannot become a silent
  dependency of the detection logic itself.
- This is currently a **single Laravel application** (one codebase, one
  deploy unit), not a multi-package/multi-service architecture. Phase 0
  does not introduce a `packages/` split or separate services — that would
  be over-engineering ahead of having any audit logic to modularize.
  Enforcing the Core/Interface boundary at the package level is deferred
  until Phase 2+ (Audit Engine Foundation), once there is real code whose
  seams can be observed rather than guessed at.

## Consequences

- Phase 2+ work should land audit logic under `app/` in a
  namespace/directory dedicated to the Audit Core (e.g. `app/Audit/...`),
  kept free of `Illuminate\Http\*` and controller concerns, so it stays
  callable from Artisan, queued jobs, and (later) MCP tools alike.
  Controllers, Inertia responses, and MCP tool handlers should stay thin
  wrappers that call into that layer.
- No scanner or rule may assume an LLM is reachable. Tests for the Audit
  Core must be able to run in CI with zero external API keys configured.
- Deferring the package split means this boundary is currently a
  convention enforced by code review, not by Composer/PHP namespace
  isolation. If it is not respected once audit logic exists, revisit this
  ADR rather than letting the boundary erode silently.
