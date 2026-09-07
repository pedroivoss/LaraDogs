# ADR-0011: Safe External Process Execution

## Status

Accepted (Phase 4).

**Note (Phase 4.2.1):** this ADR's "environment allowlist" and "argv-only"
decisions govern what LaraDogs' OWN process leaks into a child process —
they do not, by themselves, constrain what a CHILD PROCESS's own
config-file mechanism (npm's `.npmrc`, read automatically from the
target's working directory) can do once running, such as routing its own
network calls through a proxy declared in that file. Phase 4.2.1
confirmed empirically that this gap was real for `npm audit`
specifically (a target `.npmrc` `proxy=`/`https-proxy=` setting DID
reroute an otherwise-correctly-`--registry=`-pinned request through a
locally-controlled test listener) and closed it with explicit,
analyzer-level CLI flags (`--proxy=false --https-proxy=false
--strict-ssl=true`, or an operator-configured trusted proxy) rather than
a new `ProcessRunner`-level primitive — this class of tool-specific
config-file trust boundary is decided to belong to each analyzer, the
same way argv construction already does, not to the shared
`ProcessRunner` contract. A companion, narrower finding — that a
target's OWN scope-specific `@scope:registry=` override does NOT need
this treatment, because `npm audit`'s own implementation never queries a
per-scope registry for advisory data in the first place (verified against
source and by reproduction with a real local listener) — is recorded in
detail in
[`docs/auditing/analyzers/npm-audit.md`](../../auditing/analyzers/npm-audit.md#9-npm-configuration-security),
not here, since it is npm-specific behavior, not a new decision about
this ADR's own scope.

**Note (Phase 4.2):** the second real analyzer, `NpmAuditAnalyzer`,
reused `SymfonyProcessRunner` and this ADR's decisions unchanged — no
amendment was needed at the time. (See the Phase 4.2.1 note above for the
one gap that research later surfaced.)

**Note (Phase 4.1):** the Docker "runtime" image gap this ADR's
Consequences section flagged (no `composer` binary present) was closed —
the pinned Composer binary is now reused from the `builder` stage, and
`COMPOSER_HOME` is set to a fixed, LaraDogs-controlled directory outside
both `/app` and any mounted target, verified against a real, read-only
target mount. This is an implementation follow-through on this ADR's
existing decisions (pinned version, controlled environment), not a new
architectural decision — see
[`docs/development/docker.md`](../../development/docker.md#composer-in-the-runtime-image)
for the full account. Phase 4.1 also researched (but did not implement) a
dependency/package-scoped `AnalyzerCoverage` concept for `composer-audit`
and concluded it would be premature — see
[`docs/auditing/analyzers/composer-audit.md`](../../auditing/analyzers/composer-audit.md#dependency-coverage-research-phase-41).

## Context

[ADR-0009](ADR-0009-audit-engine-foundation.md) recorded the
`ProcessRunner` contract (`ProcessCommand`/`ProcessResult`/`ProcessRunner`)
as a placeholder in Phase 2, with zero implementation and zero callers —
it existed only so a future real analyzer would be written against a
stable seam instead of improvising `shell_exec()` inline. Phase 4 needed
both: a real implementation, and the first real caller
(`ComposerAuditAnalyzer`, running `composer audit` against an untrusted,
analyzed project's `composer.json`/`composer.lock`).

Running a real subprocess against/near untrusted project state raises
concrete risks this ADR had to close, not merely acknowledge:

1. **Command injection.** Any code path that builds a shell string from
   data influenced by the target project is a shell-injection surface.
2. **Environment leakage.** A naive subprocess call inherits its parent's
   full environment — including LaraDogs' own `DB_PASSWORD`, API keys,
   MCP credentials, and CI tokens — none of which the audited tool needs
   or should ever see.
3. **Target-controlled code execution.** Composer (and many similar
   tools) can execute arbitrary code from the very project being audited,
   via `scripts`/plugins, unless explicitly disabled.
4. **Resource exhaustion.** A hanging or runaway tool must not hang
   LaraDogs itself (timeout), and unbounded output capture must not
   exhaust memory (output cap).
5. **Ambiguous outcomes.** "The process never started," "the process
   timed out," "the process ran and reported success/failure," and "the
   process exceeded the output cap" are four different facts a caller
   must be able to tell apart — conflating any two of them risks a false
   "clean" result.

A concrete question this ADR had to resolve empirically, not by reading
documentation alone: does a missing target executable surface as a
`proc_open()`-level start failure, or as a normal (non-zero) exit code?
Getting this wrong would misclassify a real, common failure mode.

## Decision

- **Use Symfony Process, not a new dependency.** Verified via
  `composer show symfony/process` before writing any code: it is already
  a transitive dependency of `laravel/framework` (v7.4.18 at the time of
  this phase). No new Composer package was added.
- **`SymfonyProcessRunner` is the first, and today the only,
  `ProcessRunner` implementation** (`app/Audit/Engine/Process/`).
  Constructed with `argv` (an array) — never Symfony's separate
  `Process::fromShellCommandline()` shell-string factory — so there is
  structurally no shell to inject into.
- **Environment is a true allowlist, verified against the exact vendored
  Symfony source, not assumed from familiarity with the library.**
  Reading `Process::start()`/`getDefaultEnv()` showed Symfony always
  backfills any environment key not explicitly set with the current PHP
  process's own inherited environment — so `setEnv($allowlist)` alone is
  NOT sufficient and would still leak everything else. The fix, verified
  at the exact `$envPairs` construction line: an environment entry whose
  value is boolean `false` is treated by Symfony as "omit this variable."
  `SymfonyProcessRunner::buildAllowlistedEnv()` sends the given allowlist
  plus `false` for every other variable the current process would
  otherwise leak in — a real allowlist, not a merge.
- **Timeout is real and enforced by Symfony itself**
  (`Process::setTimeout()`/`ProcessTimedOutException`), caught and
  normalized into `ProcessResult::$timedOut`, never left as an exception
  a caller must know to catch.
- **A missing working directory is a process-start failure; a missing
  executable is NOT — verified empirically, corrected from an initial
  wrong assumption.** `ProcessStartFailedException` (→
  `ProcessResult::processStartFailed()`, `exitCode === null`) was
  initially assumed to cover "the executable doesn't exist." Running it
  showed otherwise: on this platform, `proc_open()` still succeeds for a
  missing binary, and the failed exec surfaces as an ordinary non-zero
  exit code (commonly 126/127) with a "command not found"-style stderr
  message — while a nonexistent `cwd` genuinely IS caught as a start
  failure (Symfony validates it before spawning). Consequence: a caller
  that needs to detect a missing binary specifically (e.g.
  `App\Audit\Analyzers\Composer\ComposerBinaryResolver`) must check for it
  itself — `is_file()`/`is_executable()` — before ever invoking a
  `ProcessRunner`, rather than relying on `processStartFailed()`.
- **Output is capped via a streaming callback, not a post-hoc buffer
  read**, so a runaway process can't exhaust memory regardless of how
  long it keeps producing output; truncation is reported via
  `ProcessResult::$outputTruncated`, a distinct signal callers must check
  before trusting output as complete (`ComposerAuditAnalyzer` refuses to
  parse truncated JSON, treating it as a failure rather than attempting a
  best-effort partial parse).
- **Target-controlled code execution is each analyzer's own
  responsibility to prevent via its own argv choices** —
  `ProcessRunner` itself has no opinion on what command it's given.
  `ComposerAuditAnalyzer` always passes `--no-plugins --no-scripts`
  (documented Composer flags disabling plugin execution and skipping
  `composer.json`'s `scripts`) and `--locked` (so it never needs
  `composer install`, and therefore never creates a `vendor/` directory
  or runs an install-time hook in the first place).
- **The static no-shell-execution guard
  (`tests/Unit/Audit/Engine/NoShellExecutionTest.php`, introduced in
  Phase 2) was extended to also scan `app/Audit/Analyzers/`**, the new
  outer namespace where real analyzers live — so a future analyzer can't
  quietly reintroduce a manual `shell_exec()`/`proc_open()` call either.
  Symfony's own internal use of `proc_open()` inside its vendored
  `Process` class remains the explicit, accepted exception this test
  already carved out — the prohibition is against scattered manual
  implementation in LaraDogs' own code, not against Symfony's.
- **A new dependency-direction guard
  (`tests/Unit/Audit/Engine/DependencyDirectionTest.php`) statically
  proves `app/Audit/Engine/` never references `App\Audit\Findings` or
  `App\Audit\Analyzers`** — protecting the boundary the next section
  depends on.

### A related, smaller decision: connecting a real analyzer to Findings without a circular dependency

`ComposerAuditAnalyzer` legitimately needs to depend on both
`App\Audit\Engine` (it implements `Analyzer`) and `App\Audit\Findings`
(it must produce `FindingCandidate`s) — but `App\Audit\Engine` must never
depend on `App\Audit\Findings` (ADR-0010). The resolution:

- A new capability interface, `ProducesFindingCandidates`
  (`candidates(AuditContext, AnalyzerResult): list<FindingCandidate>`),
  lives in `App\Audit\Findings\Ingestion` — not in Engine — so Engine's
  own `Analyzer` contract stays completely unaware of Findings.
- Concrete analyzers that need both live in a new, deliberately outer
  namespace, `App\Audit\Analyzers\*`, which is allowed to depend on both
  Engine and Findings. `ComposerAuditAnalyzer` implements both `Analyzer`
  and `ProducesFindingCandidates` at once.
- A new orchestrator, `App\Audit\Findings\Ingestion\ScanRunner`, runs a
  real `AuditEngine`, then — for every executed analyzer that also
  implements `ProducesFindingCandidates` — looks the original analyzer
  instance back up in the same `AnalyzerRegistry` (an `AnalyzerExecution`
  deliberately carries no reference to it, by Phase 2's own design) and
  normalizes its result into candidates, before handing everything to the
  existing, unchanged `ScanRecorder`.

This was chosen over alternatives considered: making `Analyzer` itself
return `FindingCandidate`s directly (would force Engine to depend on
Findings, exactly the cycle ADR-0010 forbids), or having `ScanRecorder`
call back into each analyzer directly (would give Findings a direct
compile-time dependency on every concrete analyzer type, rather than one
narrow capability interface).

## Consequences

- `ComposerAuditAnalyzer` is the first analyzer that actually shells out,
  proving the `ProcessRunner` contract from ADR-0009 end-to-end — argv
  safety was proven with a literal shell-metacharacter injection test
  (`; touch SHOULD_NEVER_EXIST` and similar, passed as one argv element,
  asserted to be echoed back inertly with no side effect), not merely
  argued from Symfony's documentation.
- Any future analyzer that shells out to a second tool (`npm audit`,
  Semgrep, ...) reuses `SymfonyProcessRunner` as-is; no new
  `ProcessRunner` implementation should be needed unless a genuinely
  different execution model (e.g. a remote/sandboxed executor) is
  introduced later.
- Composer-sourced `Finding`s never auto-resolve yet (see
  `docs/auditing/analyzers/composer-audit.md#coverage`) — a real,
  accepted limitation of this phase, not an oversight: `AnalyzerCoverage`
  (ADR-0010's amendment) has no honest way to express "the entire rule
  universe Composer's audit model covers" the way a static-analysis
  ruleset can, and this ADR declines to invent one under this phase's
  time pressure.
- The Docker "runtime" image does not currently carry a `composer`
  binary (only the discarded "builder" stage does) — `ComposerAuditAnalyzer`
  reports `Unavailable` inside that image today. Documented as a known
  gap in `docs/development/docker.md`, deliberately not fixed here (no
  Docker redesign, no new services, per this phase's explicit scope).
