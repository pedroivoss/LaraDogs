# Roadmap

## Phases

| Phase | Name                                    | Status                    |
| ----- | --------------------------------------- | ------------------------- |
| 0     | Discovery / Architecture / Bootstrap    | **Complete** (this phase) |
| 1     | Project Discovery (stack detection)     | Not started               |
| 2     | Audit Engine Foundation                 | Not started               |
| 3     | Finding Domain + Persistence            | Not started               |
| 4     | Security / Dependency Scanners          | Not started               |
| 5     | Bug / Quality Analysis                  | Not started               |
| 6     | Performance Analysis                    | Not started               |
| 7     | Dashboard                               | Not started               |
| 8     | History / Comparison / Quality Gates    | Not started               |
| 9     | MCP                                     | Not started               |
| 10    | Authentication / MCP Credentials        | Not started               |
| 11    | Git Integration / Continuous Monitoring | Not started               |
| 12    | CI / GitHub Action                      | Not started               |
| 13    | Hardening / Release                     | Not started               |

No changes were made to this phase list during Phase 0 — the brief's
ordering (foundation → discovery → engine → domain model → scanners →
analysis → surfaces → history → MCP → auth → monitoring → CI → hardening)
is a sound dependency order and nothing encountered during bootstrap
argued for reshuffling it.

## What Phase 0 actually delivered

See the Phase 0 report for the full account. In short: a working Laravel
13 + React/Inertia application (official starter kit), SQLite, Pest,
Docker Compose for local self-hosting, and the documentation/ADR set this
file lives in. No audit-domain code.

## Deferred items (noticed during Phase 0, intentionally not built)

These are candidate improvements or gaps spotted while bootstrapping.
Recording them here instead of building them now, per the "avoid scope
creep" instruction for this phase.

- **Disable public self-registration by default.** The starter kit's
  `/register` route is open. For a security tool, this should likely be
  invite-only or admin-provisioned before real findings exist behind it.
  → Phase 10.
- **Server deployment profile** (PostgreSQL, Redis, queue workers,
  scheduler, reverse proxy, multi-project). → Tracked across Phase 3
  (multi-project schema), Phase 8 (workers for scan execution), and a
  dedicated Docker Compose profile likely alongside Phase 11/13.
- **Scanner sandboxing implementation** (containers-per-run vs. restricted
  subprocess). Constraint recorded in ADR-0004; concrete mechanism is a
  Phase 4 decision, not a Phase 0 one.
- **Dashboard visual identity.** The starter kit's default branding/welcome
  page was left untouched — reskinning is a Phase 7 concern, not a Phase 0
  one.
- **Laravel Boost** (AI-agent developer tooling for this codebase itself)
  was deliberately not installed in Phase 0 (`--no-boost`). Worth
  revisiting as an explicit, separate decision — it's a contributor-facing
  dev aid, unrelated to the audit product's own MCP surface.
- **Rate limiting / RBAC / audit logging / CSP headers** beyond Laravel
  and Fortify's defaults. → Phase 10/13.
- **CI beyond the starter kit's `tests.yml`** (lint/types/tests on push +
  PR, via `composer ci:check`). A LaraDogs-specific CI/CD story
  (e.g. running LaraDogs against itself, or against sample projects) is
  Phase 12 scope.

See [`phases.md`](phases.md) for the Phase 0 Definition of Done and what
Phase 1 should pick up first.
