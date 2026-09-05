# ADR-0004: Scanner Execution Strategy

## Status

Proposed — no scanner orchestration code exists yet (Phase 4+). This ADR
records the strategy so Phase 4 doesn't have to relitigate "build vs.
orchestrate" once implementation starts.

## Context

The product brief is explicit: **LaraDogs must not reinvent scanners.**
`composer audit`, `npm audit`, PHPStan/Larastan, ESLint, Semgrep,
OSV-Scanner, Trivy, Pest/PHPUnit already exist, are maintained by
communities with far more domain expertise than this project can have on
day one, and reimplementing any of them would be a multi-year detour from
LaraDogs' actual value proposition (detection _orchestration_,
normalization, correlation, history, and Laravel-aware analysis on top of
what those tools find).

The brief is equally explicit that analyzed code is **untrusted**: LaraDogs
will point at arbitrary third-party Laravel repositories, including their
test suites and build scripts. Running a scanner is not equivalent to
running the analyzed project's own code, but several of these tools
(PHPStan/Larastan, Pest/PHPUnit, ESLint, Semgrep) execute _some_ logic that
originates in the target repo (config files, custom rules, autoloaded
classes, test bootstrap code).

## Decision

- LaraDogs' own code will **shell out to / invoke** existing scanners as
  external processes, then normalize their output into the `Finding` model
  ([ADR-0003](ADR-0003-finding-domain-model.md)). It will not fork or
  reimplement PHPStan's type engine, ESLint's linting engine, or
  Semgrep's pattern matcher.
- Every scanner invocation must run under:
    - a **process timeout** (a hung scanner must not hang an audit run
      indefinitely);
    - **resource limits** (memory/CPU), so one misbehaving scanner run
      cannot exhaust the host;
    - a **non-root** execution context;
    - path handling that rejects path traversal outside the project root
      being audited.
- Because scanner configs and some rule engines execute code that
  originates in the analyzed (untrusted) repository, scanner execution is
  a future **sandboxing/isolation** problem, not merely a "run a CLI and
  capture stdout" problem. Phase 4 must design this in from the start
  rather than adding isolation after the fact.
- Scanner **availability detection** is part of stack detection (Phase 1):
  LaraDogs should detect which scanners are actually installed/usable for
  a given project and skip (with a clear, visible message) whatever isn't
  available, rather than failing the whole audit.

## Consequences

- LaraDogs' complexity budget goes into orchestration, normalization,
  deduplication, correlation, and history — not into parsing PHP/JS/etc.
  ourselves.
- Sandboxing strategy (containers-per-scan-run vs. restricted subprocess
  vs. something else) is intentionally **not decided** by this ADR — it is
  a Phase 4 implementation decision that needs the actual scanner list and
  threat model in front of it, not a Phase 0 guess. What _is_ decided now
  is that "just run it inline with full host access" is not acceptable.
- This reinforces [ADR-0002](ADR-0002-application-architecture.md): the
  Audit Core must keep working with zero scanners installed (degrading
  gracefully) since not every self-hosted install will have every tool
  available.
