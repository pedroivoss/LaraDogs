# Phase Definitions

## Phase 0 — Discovery / Architecture / Bootstrap

**Goal:** a solid, reproducible foundation — not audit features.

Definition of Done (reproduced from the Phase 0 execution brief; see the
Phase 0 report for the filled-in checklist with PASS/FAIL/BLOCKED per
item):

- Environment inspected; current documentation consulted (not assumed
  from training data).
- Initial architectural decisions recorded (ADRs).
- Laravel functional; React/Inertia functional.
- Local environment reproducible without Docker.
- Docker functional (or blockers documented).
- Basic tests pass; frontend build passes.
- Initial documentation exists; README matches real state.
- Architecture, security model, and roadmap documented.
- No secret committed to Git.
- No Phase 1+ feature accidentally implemented.

## Phase 1 — Project Discovery

Detect a target Laravel project's stack: Laravel version, presence of
Blade/Livewire/Inertia/React/Vue/TypeScript, Composer/NPM manifests,
Docker/CI configuration, installed scanners. This is what Phase 4's
scanner-selection step and Phase 2's engine will consume — it should be
buildable and testable against fixture projects without needing any real
scanner installed yet.

## Phase 2 — Audit Engine Foundation

The orchestration layer: given a detected stack, decide which scanners
apply, run them (in isolation — see ADR-0004), and produce raw,
un-normalized output. No `Finding` model yet — that's Phase 3. This is
where `app/Audit/...` (or equivalent namespace) is expected to be
introduced, per ADR-0002.

## Phase 3 — Finding Domain + Persistence

Implement the `Finding` model per ADR-0003, the `Scan` model per ADR-0005,
migrations, and the normalization layer that turns Phase 2's raw scanner
output into `Finding` records. Fingerprinting strategy is decided here
against real scanner output, constrained by ADR-0003 (not line-number-only).

## Phase 4 — Security / Dependency Scanners

First real scanner integrations: `composer audit`, `npm audit`,
OSV-Scanner, Trivy, Semgrep. This is also where scanner sandboxing
(ADR-0004) must actually be implemented, not just designed.

## Phase 5 — Bug / Quality Analysis

PHPStan/Larastan and ESLint integration; first Laravel-aware rules
(mass assignment, raw queries, validation gaps, etc. — see the product
brief's full list).

## Phase 6 — Performance Analysis

Heuristic detection: possible N+1, queries in loops, unbounded
`Model::all()`, missing eager loading, sync-heavy operations that should
be queued.

## Phase 7 — Dashboard

Findings list/detail pages, project/scan navigation, health overview.
Builds on the Inertia/React foundation from Phase 0 but is not itself
Phase 0 work — no dashboard-specific pages exist yet beyond the starter
kit's own settings/auth pages.

## Phase 8 — History / Comparison / Quality Gates

NEW/RESOLVED/UNCHANGED/REGRESSED comparison between scans; quality gates
(fail a build/PR if new CRITICAL findings appear, etc.).

## Phase 9 — MCP

Implement the MCP server described in `docs/integrations/mcp.md` against
a real Finding/Scan implementation.

## Phase 10 — Authentication / MCP Credentials

Harden the auth model beyond the starter-kit default (see
`docs/architecture/security-model.md`'s known limitation on public
registration); implement MCP credential issuance/scoping per ADR-0006.

## Phase 11 — Git Integration / Continuous Monitoring

Watch a repository for changes and trigger scans automatically.

## Phase 12 — CI / GitHub Action

Package LaraDogs as something a GitHub Actions workflow can invoke as a
quality gate on external projects.

## Phase 13 — Hardening / Release

Security review, RBAC, rate limiting, audit logging, CSP/headers, and
whatever else accumulated as a "deferred to hardening" note across prior
phases (see `roadmap.md`'s deferred-items list, which Phase 13 should
treat as a checklist to revisit, not close by default).
