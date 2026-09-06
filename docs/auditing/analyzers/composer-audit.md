# Analyzer: Composer Audit

**Status: Implemented (Phase 4).** The first real analyzer:
`App\Audit\Analyzers\Composer\ComposerAuditAnalyzer` runs
`composer audit` against a project's locked PHP dependencies and
normalizes real security advisories into `FindingCandidate`s. See
[`../audit-engine.md`](../audit-engine.md) for the Engine contract this
implements, [`../../development/process-execution.md`](../../development/process-execution.md)
for the process-execution boundary it runs through, and
[ADR-0011](../../architecture/decisions/ADR-0011-safe-external-process-execution.md)
for the design decision.

## Scope

Only `composer audit` — deliberately no `npm audit`, OSV-Scanner, Trivy,
Semgrep, PHPStan/ESLint/Pest run against the target, no custom rules, no
dashboard, no MCP server, no Git monitoring, no GitHub Action. This phase
is one real, complete vertical slice: Discovery → Engine → safe process
execution → Composer audit → parsing/normalization → `FindingCandidate` →
Finding persistence/lifecycle.

## Composer semantics this analyzer relies on (researched, not assumed)

Context7 was unavailable this phase (the same authentication failure seen
in prior phases: `Invalid API key... should start with 'ctx7sk'`) —
registered per standing instructions, and official Composer documentation
plus the raw `composer/composer` GitHub source (`Auditor.php`,
`SecurityAdvisory.php`, `AuditCommand.php`) were used instead, since both
are authoritative primary sources for behavior that must be exact
(exit codes, JSON schema).

- **Minimum version: 2.4** — `composer audit` did not exist before it.
  `ComposerAuditAnalyzer::availability()` runs a cheap `composer --version`
  call (never `audit` itself) and rejects anything below `2.4.0`.
- **`--locked`** — audits directly from `composer.lock`, without needing
  `vendor/composer/installed.json` or a prior `composer install`. This is
  the load-bearing flag that lets this analyzer run against a project
  that was only ever discovered, never installed.
- **`--no-plugins --no-scripts`** — global Composer flags, documented to
  disable plugins and skip `scripts` defined in `composer.json`,
  respectively. This is the concrete mitigation against a malicious
  target's `post-install-cmd`/`post-update-cmd` (or a malicious plugin)
  ever running as a side effect of being audited — always passed,
  unconditionally.
- **`--format=json`** — machine-readable output; schema below.
- **Exit codes are fixed, not a count.** Verified directly from
  `Auditor.php` source (an earlier secondary source claimed the exit code
  "represents the number of matched advisories" — checking the actual
  source disproved this before it could reach the implementation):
  `STATUS_OK = 0`, `STATUS_FAILED = 1`. `1` means advisories affect
  packages, OR abandoned packages exist under fail-mode, OR filtered
  packages exist under fail-mode — **not** "the analyzer failed."
  `ComposerAuditAnalyzer::run()` deliberately never gates on exit code;
  whether the run is trustworthy is decided entirely by whether the JSON
  parsed and whether repositories were reachable (see below). This exact
  behavior — advisories found, exit code 1, `AnalyzerResult::Passed` — is
  covered by a dedicated test and was also confirmed live: running
  `php artisan laradogs:audit` against a real fixture with a real,
  network-connected `composer` binary produced exit code `1` with 3 real
  advisories against `laravel/framework`, correctly reported as `Passed`.
- **Always needs network access** to query Packagist's advisory API — see
  [Network and tool failures](#network-and-tool-failures).

### JSON schema (verified against source, not assumed)

Top-level keys: `advisories` (map of package name → list of advisory
objects, always present, possibly empty), `abandoned` (map of package name
→ replacement package name | `true` | `null`, always present),
`unreachable-repositories` (present **only when non-empty** — the
network/tool-failure signal), `ignored-advisories` / `filter` (present,
not currently consumed).

Per-advisory fields: `packageName`, `advisoryId` (always present, never
null — chosen as the basis for this analyzer's stable rule identity),
`affectedVersions` (a pretty constraint string), `title`, `sources` (an
array; sub-structure not fully pinned down upstream, parsed defensively),
`cve` (nullable — frequently absent), `link` (nullable), `severity`
(nullable — **frequently null in real advisories**, confirmed live: one
of three real advisories returned by a live run against
`laravel/framework` had `severity: null`), `reportedAt` (an RFC3339
string).

`App\Audit\Analyzers\Composer\ComposerAuditParser` is the dedicated,
defensive parser for this schema — deliberately never mixed into
`ProcessRunner`/the analyzer itself. It tolerates a missing/wrong-typed
field on any single key by falling back to null/empty rather than
crashing, and returns `null` for the whole report only when the top-level
JSON itself is unparseable or structurally wrong (not an object, or
`advisories` isn't an object/array) — see
`tests/Unit/Audit/Analyzers/Composer/ComposerAuditParserTest.php`.

## Applicability / availability

- **Applicable**: `composer.json` **and** `composer.lock` both present
  (from `ProjectProfile.backend.composer`/`composerLock`). A Composer
  project with no lock file is explicitly **not applicable** — this
  analyzer never runs `composer install` to create one (see
  [Target safety](#target-safety) below).
- **Available**: the `composer` binary resolves (see below) and its
  `--version` output parses to `>= 2.4.0`. `audit` itself is never run
  during availability checking.

## Target safety

- The `composer` binary is resolved by
  `App\Audit\Analyzers\Composer\ComposerBinaryResolver` from **exactly
  two sources**: an explicit `config('laradogs.composer.binary')`
  override, or LaraDogs' own `PATH` via Symfony's `ExecutableFinder` —
  **never** anything read from or influenced by the target project.
- `composer install` is never run — `--locked` makes this unnecessary; no
  `vendor/` directory, no `composer.lock` (if absent), and no script
  execution are ever created/triggered by this analyzer. Verified by the
  end-to-end test, which reuses the Phase 1 `no-code-execution` fixture
  (whose `composer.json` declares malicious `post-install-cmd`/
  `post-update-cmd` scripts) and asserts its marker file is never created.
- `--no-plugins --no-scripts` are always passed (see above) — this is the
  concrete mitigation, not merely "we assume the target won't run
  scripts."
- Environment sent to the subprocess is an explicit allowlist (see
  [`process-execution.md`](../../development/process-execution.md)),
  never the target's `.env` (never read by LaraDogs at all) and never
  LaraDogs' own full environment.

## Severity and confidence

Composer's own `severity` string is mapped directly:
`critical`/`high`/`medium`/`low` → the matching `Severity` case;
anything else, including `null` (common in real advisories — confirmed
live), → the new `Severity::Unknown` case, added this phase specifically
because it's a genuine domain need, not an aesthetic one: a real advisory
often carries no severity from its source, and inventing one (or silently
mapping it to `Info`, which would misrepresent "not a problem") would be
worse than saying "unknown." LaraDogs never promotes "a vulnerability
exists" to `Critical` on its own judgment.

`Confidence::High` is used for every candidate, unconditionally — this
reflects confidence in the **match** (Composer matched an official
advisory against a locked package version; there is no heuristic/partial
match to hedge against here), not the advisory's real-world impact, which
is `Severity`'s job.

## Rule identity

`FindingCandidate.ruleId` is `"{packageName}:{advisoryId}"` — e.g.
`vendor/vulnerable-package:PKSA-abcd-1234-efgh`. `advisoryId` alone was
considered (Packagist's own scheme already appears to scope ids per
package) but the package name was folded in anyway for a stable,
deterministic identity that doesn't depend on that scheme's uniqueness
guarantee holding forever. Neither `title` (human-facing, can be edited
upstream) nor `cve` (frequently absent) is used as identity.

`Fingerprinter` v1 (Phase 3: SHA-256 of `analyzer_id + rule_id +
normalized_path + normalized_snippet`) needed no changes — it already
tolerates null `filePath`/`codeSnippet` (a dependency finding has
neither), verified by the existing Phase 3 `vulnerableDependency()`
synthetic-candidate test and re-exercised end-to-end by this phase's own
pipeline test.

## Coverage: always `Unknown` — a deliberate, documented limitation

`ComposerAuditAnalyzer::run()` always declares
`AnalyzerCoverage::unknown()`, regardless of outcome. This was a genuine
design question, resolved as follows: `AnalyzerCoverage::verifies($ruleId)`
exists to answer "did this run actually confirm `$ruleId` is no longer
present," which is what safely authorizes auto-resolving an old
`Finding`. Composer's audit model has no concept of "the exact universe
of rule ids this run checked and confirmed absent" the way a static
analysis ruleset does — it re-checks every locked package against the
_current_ advisory database and reports only what currently matches.
Declaring `AnalyzerCoverage::explicit($foundAdvisoryIds)` using this run's
_found_ advisory ids would be actively wrong: by construction, an advisory
id that stopped appearing is never in that list either, so it could never
actually authorize resolving the finding it produced — the mechanism would
look like it does something but never actually would. Declaring `Full`
would be an unjustified upgrade with no evidence behind it.

**Consequence, accepted and documented rather than worked around under
time pressure: Composer-sourced findings never auto-resolve in this
version.** A fixed vulnerability's `Finding` stays open until manually
resolved/suppressed. Phase 4.1 researched, deliberately, whether this
should change — see below.

### Dependency coverage research (Phase 4.1)

**Question:** when `composer audit --locked` completes with `Passed`
(valid JSON, no `unreachable-repositories`, no truncation, no timeout),
what exactly can we affirm was verified? Specifically: is it safe to say
"all of `composer.lock`'s packages were checked against the available
advisory sources this run" — and if so, is `AnalyzerCoverage::full()` (or
a new package-scoped coverage concept) justified?

**Method:** read the actual `composer/composer` source at the exact
pinned Docker version (`2.10.3`) — `Auditor::audit()`,
`RepositorySet::getMatchingSecurityAdvisories()`/
`getSecurityAdvisoriesForConstraints()`, and `PolicyConfig`/
`IgnoreUnreachable` — plus targeted local reproductions with the real
`composer` binary, rather than reasoning from the JSON schema alone.

**Findings:**

1. `unreachable-repositories` is populated whenever a queried repository
   throws a network-level `TransportException`, **regardless of** a
   project's own `config.policy.ignore-unreachable` (or legacy
   `config.audit.ignore-unreachable`) setting — that setting only
   controls whether Composer aborts with a hard error or continues past
   the failure; either way, the unreachable repository is still recorded
   in the JSON output. **This is good news:** a target project cannot
   silently suppress the unreachable-repository signal this analyzer
   already fails closed on.
2. **A more fundamental gap, confirmed empirically, not merely
   theorized:** a target's own `composer.json` can disable Packagist
   outright (`"repositories": {"packagist.org": false}`) with **no**
   alternative advisory-capable repository configured. `composer audit
--locked` against such a project returns a **perfectly clean** result
   — `advisories: []`, `abandoned: []`, exit code `0`, **no
   `unreachable-repositories` key at all** — even against a
   `composer.lock` locking a package version with real, known advisories
   (reproduced directly: `laravel/framework 13.9.0`, which every other
   test/manual run in this project shows has 3 real advisories, audited
   as fully clean once Packagist is disabled in `composer.json`). This is
   not a network failure, a timeout, or truncated output — Composer runs
   perfectly, correctly, and successfully queries **zero** repositories,
   because none of the configured ones claim to provide advisories. There
   is no field in Composer's JSON schema that signals "zero
   advisory-capable repositories were consulted."

**Conclusion:** it is **not** safe to claim "all of `composer.lock`'s
packages were checked against the available advisory sources" purely
from a `Passed` result with no `unreachable-repositories` — a project can
make itself untestable in a way that is indistinguishable, from
Composer's own JSON output alone, from a genuinely clean audit. This
rules out `AnalyzerCoverage::full()` outright (not merely "insufficient
evidence yet" — actively disproven for the general case) and means a new
package-scoped coverage concept (`PackageCoverage`, `DependencyCoverage`,
or similar, as sketched in the Phase 4.1 brief) would be premature: such
a concept would need a trustworthy "this package was actually checked"
primitive, and this research shows LaraDogs cannot currently establish
that primitive from `composer audit`'s output alone — the exact same
"looks done, wasn't" failure mode the rule-based `Explicit`/`Full`
mechanism was designed to prevent for static-analysis rules turns out to
apply here too, just at the repository-configuration level instead of
the ruleset level.

**Decision: (C) — not enough evidence yet; keep `AnalyzerCoverage::unknown()`.**
Per the standing instruction for this research ("if in doubt, keep
`Unknown` — a false-unresolved finding is preferable to a false-resolved
one"), and given a concrete, reproduced counterexample now exists (not
just a theoretical gap), there is no responsible path to `Full` or a new
coverage primitive in this phase. No `AnalyzerCoverage` code changed as a
result of this research — this section documents the investigation and
its (negative) conclusion, not a new mechanism.

**On the user's own worked example** (package upgraded between scans,
advisory naturally disappears — "we probably want to resolve the
Finding"): this research shows why that intuition, while reasonable,
still isn't safe to automate today. "The advisory disappeared" and "the
advisory disappeared because Packagist got disabled in `composer.json`
between scan #1 and scan #2" produce byte-identical JSON output. Until
LaraDogs can independently verify that an advisory-capable repository was
actually queried (a candidate for future work — e.g. cross-checking the
target's own `repositories`/`config` keys, already parsed defensively by
Phase 1's `ComposerManifest`, for an explicit Packagist disable — or, more
robustly, querying advisories from a source LaraDogs controls rather than
trusting the target's repository configuration at all), auto-resolving on
"advisory no longer reported" carries exactly the risk this whole
mechanism exists to prevent.

## Abandoned packages

Composer's `abandoned` map (package → replacement | `true` | `null`) is
reported as **one informational `AnalyzerDiagnostic`** (`DiagnosticLevel::Info`),
never as a `Finding`. Rationale: an abandoned package is a maintenance
signal, not a security advisory — conflating the two would misrepresent
severity/urgency (an abandoned-but-otherwise-fine package is not
"vulnerable"). Turning this into real `Finding`s (option A from the
phase's own decision menu) is deferred, not rejected — it would need its
own severity/confidence semantics distinct from advisories, which is
scope this phase didn't need to invent.

## Network and tool failures — fail closed, always

`unreachable-repositories` being non-empty means Composer could not query
one or more advisory sources — this is reported as `ExecutionStatus::Failed`
(never `Passed`), with the unreachable list carried in
`rawMetadata['unreachable_repositories']` for diagnosis. **A network
failure must never produce a false-clean scan** — no `FindingCandidate`s
are ever produced from a run in this state (`candidates()` returns `[]`
whenever `status !== Passed`). The same fail-closed handling applies to:
malformed/unparseable JSON, and output that was truncated by the
`ProcessRunner`'s size cap (checked explicitly, since truncated JSON that
happens to still parse must not be trusted as complete). A timeout is
reported as its own distinct `ExecutionStatus::TimedOut`, never silently
folded into `Failed`.

**Explicitly out of scope for this section:** a target project disabling
its own advisory-capable repository (e.g. `"repositories": {"packagist.org":
false}` with no replacement) is a **different** category of problem —
Composer doesn't fail, time out, or report anything unreachable; it just
successfully queries zero repositories and returns a clean-looking
result. This is not a "network/tool failure" this analyzer can detect or
fail closed on today — see
[Dependency coverage research](#dependency-coverage-research-phase-41)
and [Known limitations](#known-limitations).

## `FindingCandidate` shape

- `ruleId`: `"{package}:{advisoryId}"` (see above).
- `severity` / `confidence`: see above.
- `title`: Composer's own advisory `title`, used as-is (already
  human-facing by design upstream).
- `description`: `"Affects {package} versions {affectedVersions}."`
- `cve`, `references` (the advisory `link`, when present).
- `metadata` (structured, redacted via the existing `EvidenceRedactor`
  before persistence — unchanged from Phase 3): `package_name`,
  `advisory_id`, `affected_versions`, `sources`, `reported_at`.
- `filePath`/`lineStart`/`lineEnd`/`codeSnippet`/`contextCode`: all
  `null` — a dependency finding has no source location.
- `analyzerVersion`: the resolved Composer version string (e.g.
  `"2.8.1"`), carried from `availability()` through `rawMetadata`.
- `ruleVersion`: `null` — Composer has no analogous "rule version"
  concept to report.

## Integration with the Engine/Findings pipeline

A new interface, `App\Audit\Findings\Ingestion\ProducesFindingCandidates`
(`candidates(AuditContext, AnalyzerResult): list<FindingCandidate>`),
lives in `Findings\Ingestion` — not in `App\Audit\Engine`, which must
never depend on Findings (ADR-0010). `ComposerAuditAnalyzer` implements
both `Analyzer` (Engine) and `ProducesFindingCandidates` (Findings),
living in a new, deliberately outer namespace,
`App\Audit\Analyzers\Composer`, that is allowed to depend on both.

A new orchestrator, `App\Audit\Findings\Ingestion\ScanRunner`, ties this
together: it runs a real `AuditEngine`, then — for every executed
analyzer that also implements `ProducesFindingCandidates` — looks the
original analyzer instance back up in the same `AnalyzerRegistry` (an
`AnalyzerExecution` deliberately carries no reference to it) and
normalizes its result into candidates, handing everything to the
existing (unchanged) `ScanRecorder`. No circular Engine↔Findings
dependency is introduced — `ScanRunner` itself lives in `Findings\Ingestion`,
alongside `ScanRecorder`, which already depended on Engine types
(`AuditRunResult`) since Phase 3.

## Pipeline

```
Project → ProjectDiscovery::discover() → ProjectProfile
        → AuditContext
        → AuditEngine::run() [ registry: ComposerAuditAnalyzer ]
        → AnalyzerResult (Passed/Failed/TimedOut, rawMetadata, coverage)
        → ComposerAuditAnalyzer::candidates() → list<FindingCandidate>
        → ScanRunner → ScanRecorder (Phase 3, unchanged)
        → Scan / ScanAnalyzerExecution / Finding / FindingOccurrence persisted
```

Exercised end-to-end by
`tests/Feature/Audit/Analyzers/Composer/ComposerAuditEndToEndTest.php`
(real Discovery, real Engine, a fake/scripted `ProcessRunner`, real
`ScanRunner`/`ScanRecorder`, real persistence) and manually, once, against
a **real** `composer` binary with real network access (see
[Manual verification](#manual-verification-performed) below).

## CLI

**IMPLEMENTED**: `php artisan laradogs:audit {path} [--json]
[--analyzer=composer-audit]`. A thin adapter (`App\Console\Commands\AuditCommand`)
over `ProjectDiscovery` + `AuditEngine` — no analyzer logic of its own.
Deliberately **does not** create a `Project`/`Scan` or otherwise persist
anything; it prints one real `AuditRunResult` (human-readable or
`--json`) and stops there. An ad-hoc CLI invocation has no well-defined,
stable `Project` identity to attach history to — inventing one here (e.g.
find-or-create a `Project` by path on every CLI call) would be scope this
command doesn't need; the persisted path
(`App\Audit\Findings\Ingestion\ScanRunner`) is exercised by the automated
test suite and is available to a future caller (API, scheduled job) that
manages `Project` lifecycle explicitly.

`--analyzer=` scopes execution to one analyzer id by constructing a
temporary, single-analyzer `AnalyzerRegistry` for that run — the shared,
container-bound registry (which today contains only `composer-audit`,
registered in `App\Providers\AppServiceProvider`) is left untouched.

## Configuration

`config/laradogs.php` gained two sections (values from LaraDogs' own
environment only — never the target's `.env`, which LaraDogs never
reads):

- `process.timeout_seconds` / `process.max_output_bytes` /
  `process.env_allowlist` — defaults for any `ProcessRunner` caller.
- `composer.binary` (override; `null` triggers PATH resolution) /
  `composer.timeout_seconds`.

No database migration was added — this phase's new data (advisory
metadata, coverage) fits entirely into the existing `findings.metadata`/
`scan_analyzer_executions.coverage` JSON columns from Phase 3/3.1.

## Docker impact

**Fixed in Phase 4.1** (was a known gap after Phase 4 — see
[`../../development/docker.md`](../../development/docker.md#composer-in-the-runtime-image)
for the full account). The `runtime` stage now carries the same pinned
Composer binary the `builder` stage already used (reused via
`COPY --from=builder`, not a second image pull), plus a fixed,
LaraDogs-controlled `COMPOSER_HOME`. Verified with a real
`docker compose build` + a running container:
`ComposerAuditAnalyzer::availability()` reports `AVAILABLE` (Composer
2.10.3 detected) inside the official runtime image, and a real
`laradogs:audit` run against a **read-only-mounted** fixture directory
succeeded, returning real advisories, with Composer's cache verified to
land only under `/home/laradogs/.composer` — never inside `/app` or the
mounted target.

## Tests

- `tests/Unit/Audit/Engine/Process/SymfonyProcessRunnerTest.php` — the
  real `ProcessRunner`, against LaraDogs' own PHP fixture scripts only
  (see [`process-execution.md`](../../development/process-execution.md)).
- `tests/Unit/Audit/Analyzers/Composer/ComposerAuditParserTest.php` —
  clean/with-advisories/with-abandoned/unreachable-repositories/malformed/
  truncated JSON, tolerance of missing/unexpected fields.
- `tests/Feature/Audit/Analyzers/Composer/ComposerAuditAnalyzerTest.php`
  — applicability (Composer+lock / Composer-no-lock / non-Composer),
  availability (binary missing / version-check failure / version too old
  / available), argv safety (`--locked --no-plugins --no-scripts`, real
  cwd), outcome handling (clean pass, advisories found with exit code 1
  still `Passed`, malformed JSON fails closed, timeout, truncated output
  fails closed, unreachable repositories fail closed, abandoned packages
  as an informational diagnostic only, coverage always `Unknown`) — using
  `tests/Support/Process/FakeProcessRunner.php`, a scripted test double
  that never spawns a real process.
- `tests/Feature/Audit/Analyzers/Composer/ComposerAuditEndToEndTest.php`
  — the full pipeline, described above.
- `tests/Feature/Audit/Analyzers/Composer/ComposerAuditRealBinaryTest.php`
  — two opt-in tests against a REAL `composer` binary, skipped unless
  `LARADOGS_TEST_REAL_COMPOSER=1` is set: one plain real-audit run, and
  (Phase 4.1) one proving a real audit against a **filesystem-read-only**
  target directory with `COMPOSER_HOME` pointed outside it, asserting the
  target's contents are byte-for-byte unchanged afterward. The automated
  suite never requires internet access to pass.
- (Phase 4.1) Two additions to `ComposerAuditAnalyzerTest.php`: `COMPOSER_HOME`
  forwarding through the environment allowlist (proxying the Docker
  runtime's container-level env var), and a portable, non-Docker,
  filesystem-permission-based read-only-target check.

### Manual verification performed

Phase 4: `php artisan laradogs:audit tests/Fixtures/discovery/laravel-with-composer-lock --json`
was run against a real, network-connected `composer` binary. It found 3
real advisories against `laravel/framework v13.9.0` (one with
`severity: null`, confirming the `Severity::Unknown` design decision was
addressing a real case, not a hypothetical one), exit code `1`, correctly
reported as `ExecutionStatus::Passed`, with no `vendor/` directory or
other mutation created in the fixture directory.

Phase 4.1: a real `docker compose build` + a running container (non-root,
healthy) was used to confirm `composer --version` (2.10.3) and a real
`laradogs:audit` run succeed inside the official runtime image against a
**read-only-mounted** (`:ro`) fixture — see
[Docker impact](#docker-impact) and
[`../../development/docker.md`](../../development/docker.md) for the full
account. All test containers/volumes/temp directories created for this
verification were removed afterward.

## Known limitations

- Coverage is always `Unknown` — Composer findings do not auto-resolve
  yet (see [Coverage](#coverage-always-unknown--a-deliberate-documented-limitation)
  and the [Phase 4.1 research](#dependency-coverage-research-phase-41)
  that confirmed this should stay the case).
- **A target project can disable its only advisory-capable repository
  (e.g. `"repositories": {"packagist.org": false}`) and receive a
  perfectly clean, valid-JSON, exit-0 audit result** — confirmed by
  reproduction, not merely theorized (see the
  [Phase 4.1 coverage research](#dependency-coverage-research-phase-41)).
  This analyzer cannot currently detect or fail closed on this specific
  case — it is a different failure category than the network/tool
  failures this analyzer already handles. Concrete future work: cross-check
  the target's own `repositories`/`config` keys (already parsed
  defensively by Phase 1's `ComposerManifest`) for an explicit Packagist
  disable with no replacement, or query advisories from a source LaraDogs
  controls instead of trusting the target's repository configuration.
- Abandoned packages are diagnostic-only, never a `Finding`.
- Only one scanner exists — no correlation across scanners, no
  Laravel-aware rule layer.
