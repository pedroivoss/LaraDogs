# ADR-0009: Audit Engine Foundation — Analyzer Contract and the Process Execution Boundary

## Status

Accepted (Phase 2).

**Note (Phase 4):** The `ProcessRunner` contract recorded below got its
first real implementation (`SymfonyProcessRunner`) and first real caller
(`ComposerAuditAnalyzer`) — see
[ADR-0011](ADR-0011-safe-external-process-execution.md), which owns the
implementation-level decisions (environment allowlisting, timeout/output
capping, process-start-failure semantics) this ADR only anticipated.

**Note (Phase 3.1):** `AnalyzerResult` gained an `AnalyzerCoverage` field
(`App\Audit\Engine\Execution\AnalyzerCoverage`/`CoverageMode`) — an
analyzer's own declaration of what its execution actually verified,
independent of `status`. This is a small, additive extension of the
contract this ADR already established (analyzer-declared execution
metadata), not a new architectural decision; the reasoning lives in
[ADR-0010's amendment](ADR-0010-finding-identity-occurrences-and-lifecycle.md#amendment-phase-31-coverage-gated-auto-resolution),
since coverage exists specifically to make Finding auto-resolution safe.

## Context

Phase 1 (Project Discovery) produces a `ProjectProfile` describing a
project's detected stack, without running anything. The product brief's
next step is an orchestration layer that decides which analyzers apply to
that profile, whether each one's tooling is actually usable on this host,
runs the applicable/available ones, and normalizes whatever comes back —
success, failure, an exception, or a timeout — into one consistent shape.

No real analyzer (`composer audit`, `npm audit`, PHPStan, Semgrep, ...)
exists yet (Phase 4+). Building the orchestration layer now, against
synthetic analyzers, means the plan/execution/normalization contracts are
validated before the first real integration has to also debate them.

Two design questions recur across the product brief and prior ADRs and
needed a decision here:

1. **Applicability vs. availability.** A project either does or doesn't
   call for a given analyzer's rules (a fact about the project — e.g. no
   `package.json` means an npm-based analyzer has nothing to check). A
   host either can or can't currently run that analyzer's tooling (a fact
   about the environment — e.g. `composer` isn't on `PATH`). Conflating
   these into one boolean loses information a dashboard/CLI/MCP consumer
   needs (Project Discovery already established the same principle for
   `detected`/`not_detected`/`unknown` — see ADR-0008).
2. **Where does process execution eventually live?** [ADR-0004](ADR-0004-scanner-execution-strategy.md)
   already decided that real scanners will be shelled out to, under
   isolation (timeout, resource limits, non-root, no path traversal). This
   ADR decides _where the seam is_ between the engine (which decides
   _what_ to run) and process execution (which decides _how_ to run it
   safely) — before any code exists on either side of that seam, so the
   first real analyzer is written against a stable boundary instead of
   improvising `shell_exec()` inline.

## Decision

- **Applicability and availability are separate methods on the `Analyzer`
  contract**, checked in that order — `availability()` is never even
  called for a not-applicable analyzer. Both return a small status +
  reason value object (`Applicability`/`Availability`), not a boolean, so
  a dashboard/CLI can explain _why_ an analyzer was skipped.
- **The engine never calls `Analyzer::run()` while building a plan.**
  `AuditEngine::plan()` only evaluates applicability/availability;
  `AuditEngine::execute()` (or `run()`, which does both) is the only path
  that calls `run()`, and only for items the plan marked `Planned`.
- **An analyzer's `run()` can only report Passed, Failed, or TimedOut.**
  NotApplicable/Unavailable/Skipped are engine-level decisions made
  _before_ `run()` is ever invoked — an analyzer has no way to report them
  itself (enforced by `AnalyzerResult`'s private constructor). An
  exception thrown from `run()` is caught by the engine and normalized
  into a Failed execution with a diagnostic describing it — it can never
  abort the rest of the run.
- **A thin `ProcessRunner` interface is recorded now, with zero
  implementation.** `app/Audit/Engine/Process/` defines `ProcessCommand`
  (argv-only — no shell-string field exists, so nothing built against this
  contract can be tricked into shell interpolation), `ProcessResult`, and
  `ProcessRunner` (`run(ProcessCommand): ProcessResult`). Nothing in Phase
  2 constructs or calls an implementation of it; no `Analyzer`
  implementation exists yet that would need one. It exists so the boundary
  from ADR-0004 is a concrete type today, not only a paragraph of prose,
  and so Phase 4's first real analyzer is written against it from the
  start.
- **Real wall-clock timeout enforcement is deliberately not implemented in
  Phase 2.** The engine calls `Analyzer::run()` synchronously, in-process;
  PHP has no safe, portable way to preempt a blocking function call
  without `pcntl` signal tricks or a subprocess — and a subprocess is
  exactly what `ProcessRunner` will be for. `TimedOut` is a valid
  `ExecutionStatus` today, reachable only when an analyzer voluntarily
  reports it (exercised in tests via a fake `TimedOutAnalyzer` that
  returns `AnalyzerResult::timedOut()` immediately, no real sleep). Once a
  real `ProcessRunner` enforces a timeout on an actual subprocess, it
  reports it via `ProcessResult::$timedOut`, and the analyzer wrapping it
  translates that into the same `AnalyzerResult::timedOut()` call — no
  contract change needed then.
- **`continueOnFailure` (default `true`) is implemented for real, in both
  directions.** When `false`, the engine stops calling `run()` on any
  further `Planned` item once one has ended `Failed`/`TimedOut`, and marks
  the rest `Skipped` — a real, tested behavior, not just a documented
  intent. Richer fail-fast policies (e.g. stop after N failures,
  category-scoped fail-fast) and a per-analyzer timeout override are
  future work; today there is one global boolean and one global default
  timeout number (unenforced, per above).
- **`ExecutionStatus` deliberately omits `Running` and `Cancelled`**,
  despite being natural companions to the rest of the lifecycle. Phase 2's
  engine is synchronous, in-process, and has no cancellation mechanism, so
  no code path can produce either state. They can be added, with a real
  caller, once async/queued execution or run-level cancellation actually
  exists — consistent with this project's existing norm of not designing
  for a problem that isn't there yet (see ADR-0007's "if a genuine need
  emerges later, that's a new ADR").
- **No `AnalyzerCapability` concept was introduced.** `AnalyzerCategory`
  (mirroring the `Finding` category list) plus applicability/availability
  already fully determine what belongs in a plan; without a second real
  analyzer to observe what "capability" would need to distinguish beyond
  category, adding it now would be speculative.

## Consequences

- Phase 4's first real analyzer implements `Analyzer` and, for anything
  that shells out, is expected to do so only through a real
  `ProcessRunner` implementation — never `shell_exec()`/`exec()` inline.
  This ADR doesn't design that implementation; it only fixes the contract
  it must satisfy.
- A `Finding` (Phase 3) and an `AnalyzerDiagnostic` (Phase 2) remain
  distinct: a diagnostic describes a problem with the analyzer's own
  execution (binary missing, malformed output, internal error); a finding
  describes a problem the analyzer found IN the analyzed project. Nothing
  in Phase 2 normalizes analyzer output into `Finding` records —
  `AnalyzerResult` is deliberately intermediate, and Phase 3 owns that
  domain.
- `AuditPlan`/`AuditRunResult` are fully `JsonSerializable` with a stable
  shape today; a future dashboard/CLI/MCP surface can render them without
  re-deriving the plan/execution logic.
- Nothing is persisted (`ProjectProfile`, `AuditPlan`, `AuditRunResult`,
  `AnalyzerResult`) — Phase 3 owns persistence, as already scoped by
  ADR-0005/ADR-0007.
