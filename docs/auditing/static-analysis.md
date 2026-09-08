# Static Analysis (SAST) Foundation

**Status: Foundation implemented (Phase 5); first Laravel-aware rules
added (Phase 6); comprehensive rule library not yet implemented.** This
document describes the Static Application Security Testing (SAST)
vertical LaraDogs is building — what exists today (Semgrep, a 12-rule
bundled ruleset), and what is deliberately deferred to future phases (a
comprehensive Laravel-aware rule library, additional engines).

## What "SAST foundation" means here

Phase 5's objective was narrower than "detect Laravel security bugs": it
proves the entire vertical slice —

```
Project Discovery → Audit Engine → SemgrepAnalyzer → safe ProcessRunner
  → Semgrep → Semgrep JSON → SemgrepParser → FindingCandidate
  → Finding persistence → Finding lifecycle/safe resolution
```

— works end-to-end, safely, with a small, empirically-verified ruleset (2-5
rules — see [`rules.md`](rules.md)), before investing in a large rule
library against that same foundation. See
[`analyzers/semgrep.md`](analyzers/semgrep.md) for the full analyzer
design and research.

## Why Semgrep

Semgrep was chosen as the first static analyzer because it satisfies every
constraint this project's SAST work needs from day one:

- **A real, deterministic, self-hosted CE/local CLI** — no Semgrep
  account, API key, or Registry/Cloud login required (verified: LaraDogs
  never uses `--config auto`, the one path in Semgrep's own CLI that DOES
  trigger a login/network dependency).
- **An explicit rule format (YAML) LaraDogs fully controls** — rules are
  data files LaraDogs ships, not a target-influenced configuration (see
  [rule source trust](../architecture/decisions/ADR-0012-trusted-static-analysis-rules.md)).
- **An enumerable "rules executed" universe** — unlike a
  dependency-advisory scanner (Composer/npm), a static-analysis run has a
  concrete, known-in-advance set of rule ids, which is what finally lets
  LaraDogs use `AnalyzerCoverage::Explicit` for the first time (see
  [`analyzers/semgrep.md#coverage`](analyzers/semgrep.md#coverage-the-first-analyzer-to-use-explicit)).
- **Multi-language capable** — this phase only bundles PHP rules, but the
  same engine, analyzer shape, and trust model extend to Blade, JS/TS, and
  other languages in future phases without a different SAST engine.

## Rule source trust — the central design constraint

The audited project must never be able to choose which Semgrep rules run
against it. This is the single most important security property this
phase establishes — see
[ADR-0012](../architecture/decisions/ADR-0012-trusted-static-analysis-rules.md)
for the full decision and
[`analyzers/semgrep.md`](analyzers/semgrep.md#target-safety) for how
`SemgrepAnalyzer` enforces it in code (never reading a target's
`.semgrep.yml`, never using `--config auto`, always passing an explicit
LaraDogs-collected file list rather than a directory so `.semgrepignore`
can't hide code).

## What exists today

- One analyzer: `App\Audit\Analyzers\Semgrep\SemgrepAnalyzer` (see
  [`analyzers/semgrep.md`](analyzers/semgrep.md)).
- One bundled ruleset: **12 rules** under
  `resources/audit/semgrep/rules/laradogs-rules.yml` (see
  [`rules.md`](rules.md)) — Phase 5 proved the vertical with 3 rules (2
  code-quality checks, `dd()`/`var_dump()`, plus 1 security check,
  `eval()` usage); Phase 6 added the first 9 genuinely Laravel-aware
  rules on top of that foundation — SQL raw-query, Blade raw-output/XSS,
  OS command execution, filesystem/path traversal, open redirect, mass
  assignment, a second debug helper (`ray()`), a debug-config check, and
  one conservative performance hotspot (`Model::all()`). See
  [`rules/security-rules.md`](rules/security-rules.md),
  [`rules/quality-rules.md`](rules/quality-rules.md), and
  [`rules/performance-rules.md`](rules/performance-rules.md) for what
  each one actually detects. Still deliberately small — rule quality over
  rule count — and still not a comprehensive Laravel security scanner.
- Full Finding lifecycle integration, including the first real use of
  `AnalyzerCoverage::Explicit` (see
  [`analyzers/semgrep.md#coverage`](analyzers/semgrep.md#coverage-the-first-analyzer-to-use-explicit)
  and [`findings-lifecycle.md`](findings-lifecycle.md)).

## What is explicitly deferred

- **A COMPREHENSIVE Laravel-aware rule library.** Phase 6's 9 new rules
  are a first, real slice in exactly the areas a full library would cover
  (SQL injection, Blade/XSS, command execution, path traversal, open
  redirect, mass assignment) — but each is narrowly scoped (taint-mode or
  a specific structural pattern, not exhaustive coverage of every way each
  vulnerability class can occur) and the set as a whole is still only 12
  rules, not the dozens/hundreds a comprehensive library would need.
  Notably still entirely absent: authorization-bypass detection
  (evaluated and explicitly rejected this phase as too
  false-positive-prone for a naive pattern — see
  [`rules/security-rules.md`](rules/security-rules.md)), CSRF, and N+1
  query detection beyond one conservative `Model::all()` signal. The real,
  comprehensive rule library remains future work, likely its own phase,
  building on this same `SemgrepRuleCatalog`/rule-identity convention (see
  [`rules.md`](rules.md)).
- **Additional SAST/SCA engines** — OSV-Scanner, Trivy, ESLint,
  PHPStan/Larastan run against a target (as opposed to LaraDogs' own
  codebase, which already uses PHPStan/Pint/Pest for itself), Pest/PHPUnit
  run against a target.
- **Auto-fix / AI remediation** — Semgrep's own `--autofix`/`-a` is never
  used; LaraDogs is analyzer-only in this phase.
- **A dashboard, an MCP server surface for findings, Git monitoring, a
  GitHub Action** — none of these exist yet for any analyzer, Semgrep
  included.

## README truthfulness

LaraDogs' own README may accurately claim: Composer dependency auditing,
npm dependency auditing, and a Semgrep-based static analysis foundation
with a small, first Laravel-aware ruleset (SQL raw-query, Blade/XSS,
command execution, path traversal, open redirect, and mass-assignment
checks — each narrowly scoped, not exhaustive). It must not yet claim: a
comprehensive Laravel security scanner, detecting ALL SQL injection,
detecting ALL XSS, authorization-bypass detection, or a complete Laravel
ruleset — none of those are true yet, and Phase 6 explicitly evaluated
and rejected a couple of these as too false-positive-prone to ship this
phase (see [`rules/security-rules.md`](rules/security-rules.md)).
