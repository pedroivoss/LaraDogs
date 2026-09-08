# Static Analysis (SAST) Foundation

**Status: Foundation implemented (Phase 5); comprehensive rule library not
yet implemented.** This document describes the Static Application Security
Testing (SAST) vertical LaraDogs is building — what exists today
(Semgrep, a small bundled ruleset), and what is deliberately deferred to
future phases (a comprehensive Laravel-aware rule library, additional
engines).

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
- One bundled ruleset: 3 rules under
  `resources/audit/semgrep/rules/laradogs-rules.yml` (see
  [`rules.md`](rules.md)) — 2 code-quality checks (`dd()`/`var_dump()` left
  in code) and 1 security check (`eval()` usage). Deliberately simple and
  well-tested, proving the vertical rather than attempting comprehensive
  coverage.
- Full Finding lifecycle integration, including the first real use of
  `AnalyzerCoverage::Explicit` (see
  [`analyzers/semgrep.md#coverage`](analyzers/semgrep.md#coverage-the-first-analyzer-to-use-explicit)
  and [`findings-lifecycle.md`](findings-lifecycle.md)).

## What is explicitly deferred

- **A comprehensive Laravel-aware rule library** (SQL injection, XSS/unescaped
  Blade output, mass-assignment, authorization bypass patterns, and
  dozens/hundreds of similar rules) — this phase's 2-5 rules are
  deliberately NOT a preview of that library's quality bar; they exist
  only to prove the pipeline. The real rule library is future work, likely
  its own phase, building on this same `SemgrepRuleCatalog`/rule-identity
  convention (see [`rules.md`](rules.md)).
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

After this phase, LaraDogs' own README may accurately claim: Composer
dependency auditing, npm dependency auditing, and a Semgrep-based static
analysis **foundation**. It must not yet claim: a comprehensive Laravel
security scanner, detecting all SQL injection, detecting all XSS, or a
complete Laravel ruleset — none of those are true yet.
