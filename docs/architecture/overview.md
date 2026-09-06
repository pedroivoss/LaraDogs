# Architecture Overview

## The shape

```
                         LaraDogs

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
                                       |
                                      API
                                       |
                                     CI/CD
```

The **Audit Core** is the product: stack detection, scanner orchestration,
result normalization, correlation, deduplication, Laravel-aware rules, and
history — all running locally, deterministically, without any dependency
on an LLM. See [ADR-0002](decisions/ADR-0002-application-architecture.md)
for the reasoning and the hard constraint this implies (no rule or
orchestration code may require an OpenAI/Anthropic/Gemini API key to run).

CLI, MCP, Web Dashboard, and CI/CD are **adapters** on top of that core.
None of them are required for the core to function, and none of them
currently exist beyond the standard web request path that ships with the
Laravel starter kit used to bootstrap this repository.

## What exists right now (Phase 0)

This repository is currently a stock Laravel 13 application (React +
Inertia starter kit) with:

- Fortify-based authentication (login, registration, password reset, email
  verification, 2FA, passkeys) — starter-kit scaffolding, not
  LaraDogs-specific.
- A single Inertia-rendered dashboard page reachable after login.
- Configurable SQL persistence — SQLite by default for zero-config Quick
  Start, with MySQL, MariaDB, and PostgreSQL also officially supported
  (see [ADR-0007](decisions/ADR-0007-database-agnostic-persistence.md)) —
  a `/up` health-check route, and a Docker Compose setup for the
  "Personal" deployment profile.

There is **no Audit Core, no Finding model, no scanner integration, no MCP
server, and no CLI beyond stock Artisan commands.** The diagram above is
the target architecture; see [`roadmap/phases.md`](../roadmap/phases.md)
for what each subsequent phase is expected to add.

## Why a single Laravel app (for now)

Splitting the Audit Core into its own package (installable independently of
the web app) is tempting to do early, but there is no real code yet whose
seams would tell us where that boundary actually belongs. Phase 0 keeps
everything in one Laravel application; the Core/Interface separation is
enforced by convention (see ADR-0002) until Phase 2 produces enough real
audit logic to extract deliberately, rather than speculatively.

## Related documents

- [`components.md`](components.md) — the concrete pieces that exist today.
- [`data-flow.md`](data-flow.md) — request flow today, audit flow (planned).
- [`security-model.md`](security-model.md) — security posture and
  deferred hardening.
- [`decisions/`](decisions/) — the ADRs behind these choices.
