# LaraDogs Documentation

This is the documentation index for LaraDogs. It is organized so that
"how it's built" (architecture), "how to work on it" (development), "how
auditing will work" (auditing), and "how it connects to other tools"
(integrations) are separate concerns.

**Status: Early Development.** Most of what LaraDogs is _for_ — the
dashboard, MCP, most scanners, a comprehensive rule library — does not
exist in code yet, though stack detection, orchestration, the Finding
domain/lifecycle, real process execution, and three real scanners
(`composer audit`, `npm audit`, and a 12-rule bundled Semgrep ruleset,
including a first Laravel-aware slice as of Phase 6) now do. See
[`roadmap/roadmap.md`](roadmap/roadmap.md) for what phase we're in,
[`testing/manual-audit.md`](testing/manual-audit.md) to try it against a
real project, and [`../README.md`](../README.md) for the
Implemented/Planned split.

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
  (Phase 2 foundation; Phase 4/4.2/5 real analyzers/process execution).**
  The Analyzer contract, applicability vs. availability, planning,
  execution, and result normalization — now running three real analyzers
  (`composer-audit`, `npm-audit`, `semgrep`) through the same real
  `ProcessRunner`.
- [`auditing/analyzers/composer-audit.md`](auditing/analyzers/composer-audit.md)
  — **Implemented (Phase 4, Docker-hardened Phase 4.1).** The first real
  scanner: `composer audit`, its safety model, JSON schema,
  severity/confidence/coverage policy, and known limitations.
- [`auditing/analyzers/npm-audit.md`](auditing/analyzers/npm-audit.md) —
  **Implemented (Phase 4.2).** The second real scanner: `npm audit`, its
  registry/config security model (the phase's central finding), JSON
  schema, severity/confidence/coverage policy, and known limitations.
- [`auditing/analyzers/semgrep.md`](auditing/analyzers/semgrep.md) —
  **Implemented (Phase 5 foundation; Phase 6 rules).** The third real
  scanner, and the first SAST one: a 12-rule bundled Semgrep ruleset, its
  rule-source-trust model (the phase's central finding), JSON schema, and
  the first real use of `AnalyzerCoverage::Explicit`.
- [`auditing/static-analysis.md`](auditing/static-analysis.md) /
  [`auditing/rules.md`](auditing/rules.md) — **Implemented (Phase 5
  foundation; Phase 6 rules).** The SAST vertical Semgrep is the first
  slice of, and the rule catalog/identity/versioning conventions it
  establishes.
- [`auditing/rules/security-rules.md`](auditing/rules/security-rules.md),
  [`auditing/rules/quality-rules.md`](auditing/rules/quality-rules.md),
  [`auditing/rules/performance-rules.md`](auditing/rules/performance-rules.md)
  — **Implemented (Phase 6).** Every bundled rule's own detection logic,
  severity/confidence rationale, false-positive analysis, and remediation
  guidance.
- [`auditing/findings-lifecycle.md`](auditing/findings-lifecycle.md) —
  **Implemented (Phase 3).** Finding identity/occurrences, fingerprinting,
  lifecycle, and auto-resolution safety — persistent, tested, now fed by
  three real analyzers' observations (Phase 4, Phase 4.2, Phase 5)
  alongside synthetic ones in tests.
    - [`auditing/findings.md`](auditing/findings.md) — the `Finding`/
      `FindingOccurrence` field reference.
    - [`auditing/severity.md`](auditing/severity.md)
    - [`auditing/confidence.md`](auditing/confidence.md)
    - [`auditing/suppressions.md`](auditing/suppressions.md)
- [`auditing/projects.md`](auditing/projects.md) — **Implemented
  (Phase 3.2).** Registering a project, running repeated persisted audits
  against it, scan history, and the query/service layer the Dashboard
  (Phase 7) consumes — the application-level glue on top of Phase 3's
  already-complete persistence layer.
- [`dashboard.md`](dashboard.md) — **Implemented (Phase 7).** The first
  authenticated web UI: Projects, Project Detail, a filtered/paginated
  Findings browser, Scan History/Detail, Finding Detail with lifecycle
  status actions — an adapter over the query layer above, with no audit
  logic of its own. Also records the audit-trigger and stale-scan-recovery
  design decisions this phase made.
- [`development/process-execution.md`](development/process-execution.md)
  — **Implemented (Phase 4).** The real `ProcessRunner`/`SymfonyProcessRunner`
  safety model: argv-only, env allowlisting, timeouts, output capping.
- [`auditing/overview.md`](auditing/overview.md) describes the still
  **planned** rest of the pipeline (more scanners, correlation, a
  comprehensive Laravel-aware rule library).

## Testing

- [`testing/manual-audit.md`](testing/manual-audit.md) — **Implemented
  (Phase 6).** How to run LaraDogs against a real Laravel project: local
  and Docker commands (all actually run before being documented), how to
  interpret output, known limitations, how to report a false positive,
  and the no-target-mutation guarantee.

## Integrations

- [`integrations/mcp.md`](integrations/mcp.md) — the MCP interface's
  intended shape and security model (Phase 9-10, not built yet).

## Roadmap

- [`roadmap/roadmap.md`](roadmap/roadmap.md) — phase list and current
  position.
- [`roadmap/phases.md`](roadmap/phases.md) — what "done" means for each
  phase, and deferred-item notes captured during Phase 0.
