# Audit Engine (Foundation)

**Status: Implemented (Phase 2 foundation; Phase 4/4.2 add real analyzers
and the process-execution implementation).**
This is the orchestration layer between a `ProjectProfile` (Phase 1) and
real scanner integrations. It does not itself detect anything about a
project (that's Discovery) and never runs a scanner directly — that's
each concrete `Analyzer`'s own job, via `ProcessRunner`. As of Phase 4.2,
two real analyzers are registered in production, deterministically
coexisting in the same registry:
`App\Audit\Analyzers\Composer\ComposerAuditAnalyzer` and
`App\Audit\Analyzers\Npm\NpmAuditAnalyzer` — see
[`analyzers/composer-audit.md`](analyzers/composer-audit.md) and
[`analyzers/npm-audit.md`](analyzers/npm-audit.md). See
[ADR-0009](../architecture/decisions/ADR-0009-audit-engine-foundation.md)
for the engine's own foundational decisions and
[ADR-0011](../architecture/decisions/ADR-0011-safe-external-process-execution.md)
for process execution.

## Purpose

Given a `ProjectProfile` and a set of registered analyzers, decide which
analyzers apply, whether each is actually runnable on this host, build an
inspectable plan, execute the plan, and normalize every possible outcome
(success, failure, exception, timeout, skip) into one consistent shape —
without ever executing anything that originates in the analyzed project.

## Architecture

```
AuditEngine
    |
    +-- AnalyzerRegistry        (explicit registration, duplicate-id guard,
    |                             deterministic order)
    +-- Analyzer (contract)      applicability() / availability() / run()
    |
    +-- AuditPlan                built from applicability + availability,
    |     +-- AuditPlanItem      never calls run()
    |
    +-- execute(plan, context)
    |     +-- AnalyzerExecution  one per plan item, always produced
    |
    +-- AuditRunResult           run id, plan, executions, timing
```

All of it lives under `app/Audit/Engine/`, with no dependency on
`Illuminate\Http\*`, Eloquent, MCP, Docker, or the GitHub API — consistent
with [ADR-0002](../architecture/decisions/ADR-0002-application-architecture.md).
`AuditContext` carries a `ProjectProfile` (Phase 1's output) directly;
nothing here re-detects the project's stack.

## Analyzer contract

```php
interface Analyzer
{
    public function id(): AnalyzerId;
    public function name(): string;
    public function category(): AnalyzerCategory;
    public function applicability(ProjectProfile $profile): Applicability;
    public function availability(AuditContext $context): Availability;
    public function run(AuditContext $context): AnalyzerResult;
}
```

- **`AnalyzerId`** — a validated, non-empty identity (e.g.
  `composer-security`), not a plain string, so a typo can't silently
  become a different analyzer.
- **`AnalyzerCategory`** — `SECURITY | BUG | PERFORMANCE | DEPENDENCY |
QUALITY | CONFIGURATION | TEST`, mirroring the `Finding` category list
  already recorded in [`overview.md`](overview.md), so an analyzer's
  category will align with the findings it eventually produces.
- No `AnalyzerCapability` concept exists — see ADR-0009 for why it was
  considered and left out for now.

The first real `Analyzer` implementation, `ComposerAuditAnalyzer`, was
added in Phase 4; the second, `NpmAuditAnalyzer`, in Phase 4.2 — see
[`analyzers/composer-audit.md`](analyzers/composer-audit.md) and
[`analyzers/npm-audit.md`](analyzers/npm-audit.md). Both coexist
deterministically in the same `AnalyzerRegistry` (unique ids, no
collision) and one failing does not affect the other's result — see
`tests/Feature/Audit/MultiAnalyzerCoexistenceTest.php`. Synthetic
ones for testing the engine itself still live under
`tests/Support/Engine/Analyzers/` (never autoloaded in production) — see
[Fake analyzers](#fake-analyzers).

## Analyzer lifecycle

### Applicability vs. availability

Two independent questions, in this fixed order:

1. **Applicability** — does this project's detected stack call for this
   analyzer's rules? A fact about the _project_
   (`Applicability::applicable()`/`notApplicable(reason)`), decided from
   the `ProjectProfile` alone. Checked first; if not applicable,
   availability is never even evaluated.
2. **Availability** — can this analyzer's tooling actually run on _this
   host_, right now? A fact about the _environment_
   (`Availability::available()`/`unavailable(reason)`). Only checked when
   applicable.

|                                      | Applicable        | Available       | Result                               |
| ------------------------------------ | ----------------- | --------------- | ------------------------------------ |
| Laravel project, no `package.json`   | npm analyzer: No  | _(not checked)_ | `NOT_APPLICABLE`                     |
| React project, `npm` missing on host | npm analyzer: Yes | No              | `APPLICABLE` + `UNAVAILABLE`         |
| React project, `npm` present         | npm analyzer: Yes | Yes             | `APPLICABLE` + `AVAILABLE` → planned |

### Planning

`AuditEngine::plan(AuditContext $context): AuditPlan` evaluates every
registered analyzer's applicability, then (if applicable) availability,
and records one `AuditPlanItem` per analyzer — in registration order,
deterministically. **Building a plan never calls `Analyzer::run()`.**

### Execution

`AuditEngine::execute(AuditPlan $plan, AuditContext $context): AuditRunResult`
walks the plan's items in order:

- `NotApplicable`/`Unavailable` items are carried over as-is — `run()` is
  never called.
- `Planned` items are executed: `run()` is called inside a `try`/`catch`;
  a thrown exception is normalized into a `Failed` execution (with a
  diagnostic describing it) instead of aborting the run.
- If `continueOnFailure` is `false` and an earlier item ended
  `Failed`/`TimedOut`, every subsequent `Planned` item is marked `Skipped`
  instead of run (`NotApplicable`/`Unavailable` items are unaffected — they
  keep their real reason, not a generic skip).

`AuditEngine::run(AuditContext $context)` is `execute(plan($context),
$context)` in one call.

### Normalization

Whatever happens — a clean pass, a reported failure, a thrown exception,
an unavailable tool, a non-applicable analyzer, a fail-fast skip — is
represented as one `AnalyzerExecution` with the same shape: `id`, `name`,
`category`, a final `status`, an optional `result` (only present when
`run()` was actually called), an optional `durationMs`, and an optional
`note` (the applicability/availability/skip reason, when there is one).

## AuditPlan

An ordered, `JsonSerializable` list of `AuditPlanItem`s. Each item is a
plain data snapshot (id/name/category/applicability/availability/status)
— it never holds a reference to the live `Analyzer` instance, so a plan is
always safe to show in a CLI, dashboard, or MCP response without leaking
behavior. `AuditPlan::toExecute()` returns only the items that will
actually run.

## AuditRunResult

The complete, `JsonSerializable` outcome of one run: `runId`, the `plan`
that was executed (for traceability, including items that never ran),
one `AnalyzerExecution` per item in the same order, `startedAt`/
`finishedAt` (wall-clock, informational), and `durationMs` (measured with
`hrtime()`, monotonic — not derived from the wall-clock timestamps).

## Execution statuses

`Planned | Passed | Failed | TimedOut | Skipped | NotApplicable |
Unavailable` — one enum (`ExecutionStatus`) shared by plan items
(pre-execution: only `Planned`/`NotApplicable`/`Unavailable` are possible)
and executions (post-execution: any case is possible). `Running` and
`Cancelled` were considered and deliberately left out — see ADR-0009.

## Diagnostics vs Findings

An `AnalyzerDiagnostic` (`level`, `message`, optional `code`) describes a
problem with the **analyzer's own execution** — a missing binary,
malformed output, an internal error, a caught exception. A `Finding`
(Phase 3, not built yet) describes a problem the analyzer **found in the
analyzed project** — a vulnerability, a bug, a bad configuration. These
are never the same thing: `AnalyzerResult.diagnostics` only ever carries
the former. Phase 3 will define how a real analyzer normalizes its
findings; `AnalyzerResult.rawMetadata` exists as an escape hatch for
whatever intermediate shape that ends up needing, without this phase
guessing at it.

## Coverage

`AnalyzerResult` (and, via it, `AnalyzerExecution::coverage()`) carries an
`App\Audit\Engine\Execution\AnalyzerCoverage` — an analyzer's own
declaration of what its execution actually **verified**, entirely
separate from `status`. `ExecutionStatus::Passed` means the analyzer ran
without error; it says nothing about which rules it checked. Modes
(`CoverageMode`): `Unknown` (the default whenever an analyzer declares
nothing — never treated as a stronger claim just because `status` is
`Passed`), `Explicit` (a list of verified `rule_id`s), and `Full` (the
entire relevant domain was covered — only ever set explicitly, never
inferred). An optional `rulesetVersion` travels along for provenance only.

This exists in the Engine (not in the Findings domain) because coverage
is a property of an analyzer's own execution, declared the same way
`diagnostics`/`summary` already are — Phase 3's
`FindingReconciler` is its first real consumer (auto-resolution must not
trust `Passed` alone; see
[`findings-lifecycle.md`](findings-lifecycle.md#coverage-why-passed-alone-isnt-enough)
and [ADR-0010's amendment](../architecture/decisions/ADR-0010-finding-identity-occurrences-and-lifecycle.md#amendment-phase-31-coverage-gated-auto-resolution)),
but nothing about the type is Findings-specific.

## Security boundary

- **Target code execution:** nothing in the Audit Engine executes
  anything that originates in the analyzed project. This phase only
  orchestrates _synthetic_ analyzers; no real scanner integration exists
  yet. A dedicated test
  (`tests/Feature/Audit/Engine/NoTargetExecutionTest.php`) reuses Phase
  1's malicious-scripts fixture, runs it through Discovery → `AuditEngine`
  with fake analyzers, and asserts the scripts' marker file is never
  created.
- **Process execution boundary:** a real analyzer that needs to shell out
  to an external tool does so only through a `ProcessRunner`
  implementation (`app/Audit/Engine/Process/`) — never an inline
  `shell_exec()`/`exec()`/`system()`/`proc_open()`. As of Phase 4 this has
  a real implementation, `SymfonyProcessRunner` (built on Symfony
  Process — see
  [`../development/process-execution.md`](../development/process-execution.md)
  and [ADR-0011](../architecture/decisions/ADR-0011-safe-external-process-execution.md)):
    - **Shell:** `ProcessCommand` carries an `argv` list, never a shell
      string — there's no field to interpolate untrusted input into, and
      `SymfonyProcessRunner` never calls Symfony's shell-string factory.
    - **Working directory:** explicit, controlled (`workingDirectory`), not
      inherited from the caller's cwd.
    - **Environment:** an explicit allowlist (`environment` array) — a real
      allowlist, not a merge; see process-execution.md for why a naive
      `setEnv()` call would still leak the full parent environment.
    - **Timeout:** `timeoutSeconds`, enforced and reported back via
      `ProcessResult::$timedOut`.
    - **Output caps:** captured via a streaming callback with a byte cap
      (`SymfonyProcessRunner::DEFAULT_MAX_OUTPUT_BYTES`, configurable),
      reporting truncation via `ProcessResult::$outputTruncated` rather
      than buffering unboundedly.
      A static test (`tests/Unit/Audit/Engine/NoShellExecutionTest.php`) scans
      every file under `app/Audit/Engine/` AND `app/Audit/Analyzers/`
      (comments stripped) for
      `shell_exec`/`exec`/`system`/`passthru`/`proc_open`/`popen` and fails if
      any appear — proving the boundary isn't quietly bypassed by a future
      analyzer either.
- **No implicit engine behavior:** a `SpyAnalyzer` test proves the engine
  calls `applicability()`/`availability()`/`run()` exactly once each, in
  that order, only when the contract says it should — no hidden
  re-invocation, no magic beyond what's documented above.

## Fake analyzers

Under `tests/Support/Engine/Analyzers/` (never autoloaded in production):

| Analyzer                                   | Purpose                                                                                                                            |
| ------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------- |
| `AlwaysPassAnalyzer`                       | Applicable, available, always reports `Passed`.                                                                                    |
| `AlwaysFailAnalyzer`                       | Applicable, available, always reports `Failed`.                                                                                    |
| `ThrowingAnalyzer`                         | Applicable, available, `run()` throws — proves exceptions are normalized, not fatal.                                               |
| `TimedOutAnalyzer`                         | Applicable, available, reports `TimedOut` immediately (no real sleep).                                                             |
| `UnavailableAnalyzer`                      | Applicable, but reports `Unavailable`; `run()` throws if ever called.                                                              |
| `NotApplicableAnalyzer`                    | Never applicable; `availability()`/`run()` throw if ever called.                                                                   |
| `LaravelOnlyAnalyzer` / `NodeOnlyAnalyzer` | Real applicability logic against a real `ProjectProfile` (Laravel-detected / package.json-detected), reused from Phase 1 fixtures. |
| `SpyAnalyzer`                              | Counts calls to each hook, to prove exact-once invocation.                                                                         |

## CLI

**IMPLEMENTED (Phase 4; Phase 4.2 confirms it works unmodified with two
analyzers registered):** `php artisan laradogs:audit {path} [--json]
[--analyzer=composer-audit|npm-audit]` — see
[`analyzers/composer-audit.md`](analyzers/composer-audit.md#cli) and
[`analyzers/npm-audit.md`](analyzers/npm-audit.md#24-cli) for details. It
prints one real `AuditRunResult` and deliberately does not persist a
`Scan` (see those docs for why). Omitting `--analyzer` runs every
applicable, available analyzer in the registry — confirmed live against
a project with both a Composer and an npm dimension, correctly reporting
both.

## Persistence

`AuditEngine::run()` itself still persists nothing — `ProjectProfile`,
`AuditPlan`, `AuditRunResult`, and every `AnalyzerResult`/
`AnalyzerExecution` exist only in memory for the duration of one call.
Phase 3 owns the persistent domain (`Finding`/`Scan`); Phase 4 adds the
orchestration seam that connects the two —
`App\Audit\Findings\Ingestion\ScanRunner` — without either namespace
depending on the other in the wrong direction. See
[`findings.md`](findings.md#scanrunner-phase-4) and
[`components.md`](../architecture/components.md).
