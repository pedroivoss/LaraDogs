# Analyzer: Semgrep

**Status: Implemented (Phase 5 foundation; Phase 6 adds the first
Laravel-aware rules).** The third real analyzer, and the first
Static Application Security Testing (SAST) one:
`App\Audit\Analyzers\Semgrep\SemgrepAnalyzer` runs LaraDogs' own small,
bundled Semgrep ruleset (12 rules as of Phase 6 — see
[Rule catalog](#rule-catalog-resourcesauditsemgreprules) below) against a
project's first-party PHP source and normalizes matches into
`FindingCandidate`s. See
[`../audit-engine.md`](../audit-engine.md) for the Engine contract this
implements, [`../../development/process-execution.md`](../../development/process-execution.md)
for the process-execution boundary it runs through,
[ADR-0011](../../architecture/decisions/ADR-0011-safe-external-process-execution.md)
for safe process execution, and
[ADR-0012](../../architecture/decisions/ADR-0012-trusted-static-analysis-rules.md)
for the rule-source-trust decision this phase introduces.

## Scope

Phase 5 exists to prove the Static Analysis vertical end-to-end:
Discovery → SemgrepAnalyzer → safe `ProcessRunner` → Semgrep → Semgrep JSON
→ `SemgrepParser` → `FindingCandidate` → Finding persistence → Finding
lifecycle/safe resolution — shipping **2-5 simple, well-tested rules**
(3, at the time) to prove it, deliberately **not** a comprehensive
Laravel-aware rule library. Phase 6 builds on that proven vertical with
the first genuinely useful Laravel-aware rules (9 more, 12 total — see
[Rule catalog](#rule-catalog-resourcesauditsemgreprules) below and
[`../rules/security-rules.md`](../rules/security-rules.md)) — still
deliberately small (~8-15 rules, rule quality over rule count), still not
a comprehensive scanner. Explicitly out of scope through both phases:
OSV-Scanner, Trivy, ESLint, PHPStan/Larastan against the
target, Pest/PHPUnit against the target, auto-fix, AI remediation, a
dashboard, an MCP server, Git monitoring, a GitHub Action. See
[`../static-analysis.md`](../static-analysis.md) for the broader SAST
vertical this analyzer is the first slice of, and [`../rules.md`](../rules.md)
for the rule catalog/identity/versioning conventions.

## Semgrep semantics this analyzer relies on (researched, not assumed)

Everything below was verified directly against the real, locally-installed
`semgrep` CLI (v1.176.0) and, where noted, its own installed Python source
— never assumed from memory, prior familiarity, or documentation alone.

- **LaraDogs V1 requires Semgrep CE/local CLI only — no account, no API
  key, no login, no Semgrep AppSec Platform/Cloud dependency.**
  `--config auto` is never used (its own help text confirms it "will log
  in to the Semgrep Registry with your project URL" — a Registry/network
  dependency this analyzer must never have). Every scan uses only
  LaraDogs' own local rules file via `--config <bare-filename>`.
- **A real, reproduced trust gap determined the whole target-collection
  architecture:** pointing `semgrep scan` at a **directory** lets the
  target's own `.semgrepignore` hide a file from analysis entirely
  (reproduced directly: a `.semgrepignore` listing a genuinely-vulnerable
  fixture file made it disappear from `results`/`paths.scanned`
  completely). Passing that SAME file as an **explicit path argument**
  bypassed `.semgrepignore` entirely and found the match. Consequence:
  `SemgrepAnalyzer` never points Semgrep at a directory — it always builds
  its own bounded, explicit file list via `SemgrepTargetCollector` and
  passes every path directly as a `semgrep scan` argv target. This is
  covered live by
  `tests/Feature/Audit/Analyzers/Semgrep/SemgrepAuditRealBinaryTest.php`'s
  `ignore-bypass-project` fixture (real semgrep binary, opt-in).
- **`check_id` is NOT simply the rule's own YAML `id:`.** Semgrep mangles
  the `--config` path's enclosing directory into a prefix whenever that
  path has a directory component (verified: an absolute
  `--config /tmp/xyz/rules/r.yml` produced
  `check_id: "tmp.xyz.rules.<rule-id>"`; a relative `--config rules/r.yml`
  produced the shorter `"rules.<rule-id>"`). A **bare filename** with no
  directory component at all — invoked with that file's own directory as
  the process cwd — produced the clean, unprefixed rule id with **no**
  transformation. `SemgrepAnalyzer` deliberately invokes Semgrep this
  third way: `workingDirectory` is the bundled rules' own directory
  (`dirname(SemgrepRuleCatalog::rulesFilePath())`), and `--config` is the
  bare YAML filename — target files are still passed as absolute paths
  regardless of cwd. `SemgrepParser` never trusts even this as a
  guarantee: it matches every `check_id` against the known catalog rule
  ids by exact equality OR by ending in `.<known-rule-id>`, and drops
  (never misattributes) a `check_id` matching none.
- **Semgrep's own `extra.lines`/`extra.fingerprint` fields require a
  Semgrep account login** — verified empirically: both literally contain
  the string `"requires login"` in this analyzer's supported (unauthenticated,
  local-CLI-only) mode. Consequence: LaraDogs never uses either. Code
  snippets are read directly from the source file using the `start`/`end`
  line/col positions Semgrep DOES provide unconditionally (see
  [Snippet / redaction](#snippet--redaction)); LaraDogs' own `Fingerprinter`
  (Phase 3) was never going to use Semgrep's `fingerprint` anyway.
- **Symlink scan-root protection is native to Semgrep** — passing a
  symlink as a scan target produces a JSON `errors` entry
  (`"Invalid scanning root: ... is a symbolic link"`), never silent
  following or silent skipping. `SemgrepTargetCollector` still proactively
  excludes symlinks itself (`is_link()`, checked before `realpath()`) as
  defense in depth and to avoid this noisy error entirely — see
  [`../../architecture/decisions/ADR-0012-trusted-static-analysis-rules.md`](../../architecture/decisions/ADR-0012-trusted-static-analysis-rules.md).
- **Real severity vocabulary is dual**: the legacy trio
  `INFO`/`WARNING`/`ERROR` and a newer, more granular
  `CRITICAL`/`HIGH`/`MEDIUM`/`LOW` — both valid as a rule's top-level
  `severity:` YAML key, both confirmed appearing correctly in real JSON
  output. See [Severity / confidence / category](#severity--confidence--category)
  for the mapping.
- **Exit codes are fixed, not "findings found."** Verified directly: `0`
  whenever Semgrep completed — whether or not it produced results, and
  regardless of `PartialParsing`-style warnings in `errors[]`; non-zero
  specifically for a config/rule load failure (an invalid rule YAML file:
  exit `7`) or a CLI usage error (e.g. an invalid `--timeout` value: exit
  `2`). `SemgrepAnalyzer::run()` gates on `ProcessResult::successful()`
  (`exitCode === 0`) — unlike Composer/npm, this is a **normal, sufficient**
  gate for Semgrep, since Semgrep never uses a non-zero exit code to mean
  "findings present."
- **`--verbose` is required to see `paths.skipped` at all.** Verified
  empirically: a file skipped for exceeding `--max-target-bytes` is
  **silently absent** from `paths.skipped` (and from `errors`) without
  `--verbose` — the exact same scan, run again with only `--verbose`
  added, populates `paths.skipped` with
  `{"path": "...", "reason": "exceeded_size_limit"}`. `SemgrepAnalyzer`
  always passes `--verbose` for exactly this reason — without it, an
  oversized first-party file would be skipped with **zero** signal
  anywhere in the JSON, which would make the
  [coverage](#coverage-the-first-analyzer-to-use-explicit) decision unsafe.
- **The full `errors[].type` and `paths.skipped[].reason` vocabularies**
  were read directly from the installed CLI's own Python source
  (`semgrep/semgrep_interfaces/semgrep_output_v1.py`) rather than
  inferred from a handful of reproductions — see
  [Coverage](#coverage-the-first-analyzer-to-use-explicit) for how they
  drive the Explicit-vs-Unknown decision.
- **`--metrics=off`** (confirmed via the CLI's own help text) plus
  `SEMGREP_SEND_METRICS=off` (confirmed as a real, consulted environment
  variable by reading the installed CLI's own source) disable telemetry —
  passed/set unconditionally, belt-and-suspenders.
- **`SEMGREP_APP_TOKEN`** is Semgrep's own login/API-token environment
  variable (confirmed by reading the installed CLI's own
  `semgrep/app/auth.py` source: `"Using environment variable
SEMGREP_APP_TOKEN as api token"`). It is never added to
  `config('laradogs.process.env_allowlist')`, so it is never forwarded to
  the subprocess regardless of what LaraDogs' own process happens to have
  set.
- **`SEMGREP_SETTINGS_FILE`** controls where Semgrep reads/writes its
  settings (login state, anonymous telemetry id) — confirmed by reading
  the installed CLI's own `semgrep/settings.py` resolution order:
  `$SEMGREP_SETTINGS_FILE` \|\| `$XDG_CONFIG_HOME/semgrep/settings.yaml`
  \|\| `~/.semgrep/settings.yaml`. `SemgrepAnalyzer` always forces this to
  `config('laradogs.semgrep.settings_path')` (default
  `storage_path('app/laradogs/semgrep-settings.yml')`) — a developer's own
  real `~/.semgrep/settings.yaml` is never read. Confirmed empirically
  (mirroring the same pattern already used for `NPM_CONFIG_USERCONFIG`)
  that Semgrep creates BOTH the parent directory and the settings file on
  demand when the path doesn't exist yet — no pre-creation needed in
  Docker or locally.

## JSON schema (verified against the real CLI, v1.176.0)

Top-level keys: `version` (string), `results` (list), `errors` (list),
`paths: {scanned: [...], skipped: [...]}` (`skipped` only populated with
`--verbose` — see above).

Per-result: `check_id` (see [Rule identity](#rule-identity) above —
never trusted verbatim), `path` (absolute, since this analyzer always
passes absolute target file arguments), `start`/`end`: `{line, col,
offset}`, `extra: {message, metadata, severity, fingerprint, lines,
validation_state, engine_kind}` (`fingerprint`/`lines` unusable — see
above; `metadata` is the rule author's own YAML `metadata:` block, passed
through verbatim — this is how `cwe`/`references` reach LaraDogs without
any YAML parsing on the PHP side).

Per-error: `code` (int), `level` (`"error"`/`"warn"`), `type` (either a
plain string like `"SemgrepError"`/`"InvalidYaml"`, or a `[typeName,
details]` array for some types like `PartialParsing` — only the type name
is ever relevant to this codebase), `message`, `path` (nullable).

Per-skip (`paths.skipped[]`, only with `--verbose`): `path`, `reason` —
confirmed, directly from the installed CLI's Python source, to be one of:
`exceeded_size_limit`, `analysis_failed_parser_or_internal_error`,
`excluded_by_config`, `wrong_language`, `too_big`.

`App\Audit\Analyzers\Semgrep\SemgrepParser` is the dedicated, defensive
parser — deliberately never mixed into `ProcessRunner`/the analyzer
itself. Every read degrades to null/empty on an unexpected type; `parse()`
returns `null` for the whole report only when the top-level shape itself
is wrong. See `tests/Unit/Audit/Analyzers/Semgrep/SemgrepParserTest.php`.

## Applicability / availability

- **Applicable**: PHP detected (`ProjectProfile.backend.php.isDetected()`)
  — the bundled ruleset only declares PHP rules. Not simply "any
  directory" — gated on what the ruleset can actually apply to.
- **Available**: the `semgrep` binary resolves (see below) and its
  `--version` output parses to `>= 1.176.0` (`MIN_SUPPORTED_VERSION` — see
  [Known limitations](#known-limitations) for why this floor is
  conservative rather than research-backed across a version range the way
  Composer's/npm's are). A scan is never run during availability checking.

## Target safety

- The `semgrep` binary is resolved by
  `App\Audit\Analyzers\Semgrep\SemgrepBinaryResolver` from exactly two
  sources: `config('laradogs.semgrep.binary')`, or LaraDogs' own `PATH` —
  never a target's `.venv`/`node_modules`/`vendor`, never a path read from
  any target project file.
- **Rules are never sourced from the target.** Only
  `SemgrepRuleCatalog::rulesFilePath()` — a file LaraDogs itself ships
  under `resources/audit/semgrep/rules/` — is ever passed via `--config`.
  A target's own `.semgrep.yml`/`.semgrep.yaml` is never read, referenced,
  or auto-discovered. See
  [ADR-0012](../../architecture/decisions/ADR-0012-trusted-static-analysis-rules.md).
- **Targets are an explicit file list, never a directory** (see above) —
  built by `App\Audit\Analyzers\Semgrep\SemgrepTargetCollector`: a bounded,
  read-only walk (mirroring `App\Audit\Discovery\Filesystem\ProjectFilesystem`'s
  realpath-containment philosophy) that (1) rejects every symlink outright
  before ever resolving it, (2) contains every resolved path within the
  project root via `realpath()`, (3) always excludes `vendor/`,
  `node_modules/`, `storage/`, `bootstrap/cache/`, `public/build/`,
  `dist/`, `coverage/`, `.git/` — a LaraDogs-controlled exclusion list,
  never derived from the target's own `.gitignore`, (4) bounds depth
  (25), total entries visited (50,000) and total files collected (5,000).
- No remote rule source is ever consulted (`--config auto`/`p/...`/`r/...`
  Semgrep Registry references are never used) — the automated suite works
  fully offline.
- `--oss-only` forces the OSS engine explicitly (defense in depth against
  an operator's Semgrep install having a Pro-engine toggle on somewhere).
- Target PHP source is only ever read as data. Proven, not merely argued:
  `tests/Fixtures/semgrep/malicious-execution-project/app/Malicious.php`
  contains a top-level `system('touch ... /SHOULD_NEVER_EXIST')` call —
  both the FakeProcessRunner-based feature test and the real-binary opt-in
  test assert the marker file is never created, while the file's real
  `dd()` match on a later line IS still found.

## Telemetry / network

`--metrics=off` and `SEMGREP_SEND_METRICS=off` are always set (see
research above) — no scan depends on network reachability, and the
automated test suite requires none (only opt-in, real-binary tests do,
gated by `LARADOGS_TEST_REAL_SEMGREP=1`, and even those stay fully local —
no Registry, no login).

## Ignore policy

`SemgrepAnalyzer` never relies on Semgrep's own `.gitignore`/`.semgrepignore`
consultation at all — the explicit-file-list architecture above bypasses
both regardless of whether the target is a git repository. `--no-git-ignore`
is passed anyway, for clarity/defense-in-depth (its own help text notes it
has no effect outside a git repo — moot given the explicit-file-list
design, but documents intent). The exclusion policy that DOES apply
(`vendor/`, `node_modules/`, etc.) belongs entirely to
`SemgrepTargetCollector` — a LaraDogs-controlled, operator-invisible-to-the-target
decision, never something the target's own `.gitignore` influences.

## Coverage: the first analyzer to use `Explicit`

Unlike Composer/npm (always `Unknown` — see those analyzers' own docs),
Semgrep has an actual, enumerable "rules executed" universe: the bundled
catalog. `SemgrepCoverageEvaluator::isFullyCovered()` decides
Explicit-vs-Unknown with a deliberately **conservative allowlist**, not a
blocklist:

- **Any `errors[]` entry at all** — even a mere `"warn"`-level
  `PartialParsing` alongside real findings — downgrades to `Unknown`.
  There is no attempt to reason about which specific errors are "probably
  fine"; an allowlist only needs today's known-safe cases to be correct
  and fails closed against anything it has never seen, matching the
  phase's own "se houver dúvida: UNKNOWN" policy.
- **`paths.skipped[]` reasons `wrong_language`/`excluded_by_config`** are
  the only benign ones: a file the PHP-only ruleset was never going to
  apply to anyway, or an exclusion LaraDogs itself configured (this
  analyzer never passes `--exclude`/`--include` today, so this should not
  occur in practice — allowlisted for forward-compatibility, not because
  it's expected). Every other skip reason
  (`exceeded_size_limit`/`too_big`/`analysis_failed_parser_or_internal_error`)
  means a first-party file that should have been analyzable was not —
  `Unknown`.
- When fully covered: `AnalyzerCoverage::explicit(SemgrepRuleCatalog::ruleIds(),
SemgrepRuleCatalog::RULESET_VERSION)` — the **entire bundled catalog**,
  since this analyzer always runs its full ruleset (it has no concept of
  running a configurable subset of its own rules this phase).
- A genuinely empty file set (zero PHP files collected after exclusions)
  is treated as trivially fully covered — every rule was "verified"
  against everything in scope, because scope is empty — without even
  invoking Semgrep (see `SemgrepAnalyzer::run()`).

Proven end-to-end, with the REAL `SemgrepAnalyzer` +`SemgrepParser` +
`SemgrepCoverageEvaluator` across successive scans (not just the generic,
analyzer-agnostic mechanism already covered by
`tests/Feature/Audit/Findings/ReconciliationTest.php`), in
`tests/Feature/Audit/Analyzers/Semgrep/SemgrepFindingLifecycleTest.php`:

1. **Verified resolution** — a clean, fully-covered scan no longer
   observing a previously-found rule auto-resolves its `Finding`.
2. **Removed rule** — this analyzer always claims the full catalog or
   `Unknown` (no subset-of-own-rules concept exists this phase), so this
   exact scenario cannot arise from a real `SemgrepAnalyzer` run; the
   underlying mechanism (a rule id absent from `Explicit`'s list must
   never resolve that rule's finding) is proven generically — with
   `analyzerId: 'semgrep'` among others — in `ReconciliationTest.php`.
3. **Failed analyzer** — a run that fails (e.g. malformed JSON output)
   never resolves anything, regardless of what's unobserved.
4. **Unknown coverage** — a `Passed` run carrying a `PartialParsing` error
   never resolves anything, even with a rule genuinely unobserved.
5. **Regression** — a resolved finding that reappears in a later scan
   reopens automatically, recording the transition in its status history.

## Severity / confidence / category

Semgrep's own `severity` string maps to LaraDogs' `Severity`:

| Semgrep       | LaraDogs `Severity` |
| ------------- | ------------------- |
| `CRITICAL`    | `Critical`          |
| `ERROR`       | `High`              |
| `HIGH`        | `High`              |
| `WARNING`     | `Medium`            |
| `MEDIUM`      | `Medium`            |
| `INFO`        | `Info`              |
| `LOW`         | `Low`               |
| anything else | `Unknown`           |

`Confidence` and `AnalyzerCategory` deliberately do **not** come from the
rule's own YAML — Semgrep has no notion of either concept, and putting
them there would create a second source of truth alongside PHP that could
silently drift. Both live solely in `SemgrepRuleCatalog` (PHP), keyed by
rule id — see [`../rules.md`](../rules.md). `cwe`/`references` DO come
from Semgrep-native `extra.metadata` passthrough (genuinely Semgrep's own
mechanism, not a LaraDogs invention) — see the schema section above.

## Rule identity

See the [research section](#semgrep-semantics-this-analyzer-relies-on-researched-not-assumed)
above for why `check_id` is matched against a known catalog rather than
trusted verbatim, and [`../rules.md`](../rules.md) for the `laradogs.<category>.<subject>.<check>`
naming convention and per-rule catalog entries.

## Paths / locations

Every result's absolute `path` is normalized to project-relative before
persistence (`SemgrepAnalyzer::normalizePath()`) — a path that doesn't
fall within the project root at all (which should never happen, since
`SemgrepTargetCollector` only ever feeds Semgrep paths it collected from
inside the root) causes that candidate to be dropped entirely rather than
persisting an absolute host path. Line/column positions (`start`/`end`)
are preserved as-is.

## Snippet / redaction

Since Semgrep's own `extra.lines` is unusable without a login (see
research above), `SemgrepAnalyzer` reads the snippet directly from the
source file using the reported `start`/`end` line numbers — bounded by a
maximum source-file size (2,000,000 bytes; any file that large was already
at risk of being skipped by `--max-target-bytes` regardless) and a maximum
of 30 lines, so one large match can't pull an unbounded snippet into a
`Finding`. The existing `EvidenceRedactor` (Phase 3, unchanged) is applied
before persistence, same as every other analyzer.

## `FindingCandidate` shape

- `ruleId`: the matched, clean catalog rule id (e.g.
  `laradogs.quality.debug.dd-call`).
- `category`/`confidence`: from `SemgrepRuleCatalog::find($ruleId)`.
- `severity`: mapped from Semgrep's own `extra.severity` (see table
  above).
- `title`: the rule's own message, first line only (bounded); falls back
  to `"Semgrep rule {ruleId} matched."` if the message is empty.
- `description`: the rule's own full message.
- `recommendation` (Phase 6): from `extra.metadata.remediation` when the
  rule declares it — every rule in the bundled catalog does as of Phase 6.
  Mirrors the `cwe`/`references` passthrough pattern exactly (a Semgrep
  YAML `metadata:` field, never PHP-side content) — no new mechanism was
  introduced for this, only a second field read from the same place.
- `filePath`/`lineStart`/`lineEnd`/`codeSnippet`: see above.
- `cwe`/`references`: from `extra.metadata` when the rule declares them.
- `ruleVersion`: `SemgrepRuleCatalog::RULESET_VERSION` (the bundled
  ruleset's own version — see [`../rules.md`](../rules.md)).
- `analyzerVersion`: the resolved `semgrep --version` string.

## Integration with the Engine/Findings pipeline

Identical shape to Composer/npm: `SemgrepAnalyzer` implements both
`Analyzer` (Engine) and `ProducesFindingCandidates` (Findings), living in
the outer `App\Audit\Analyzers\Semgrep` namespace. No changes were needed
to `ScanRunner`/`ScanRecorder`/`FindingIngestor`/`FindingReconciler` — the
existing, analyzer-agnostic pipeline from Phase 3/4 handles this
analyzer's candidates exactly like any other.

## Pipeline

```
Project → ProjectDiscovery::discover() → ProjectProfile
        → AuditContext
        → SemgrepTargetCollector::collect() → list<absolute PHP file path>
        → AuditEngine::run() [ registry: SemgrepAnalyzer ]
        → semgrep scan --config <bare rules filename> --json --verbose
          --metrics=off --no-git-ignore --oss-only <file...>
        → SemgrepParser::parse() → SemgrepScanReport
        → SemgrepCoverageEvaluator::isFullyCovered() → Explicit | Unknown
        → AnalyzerResult (Passed/Failed/TimedOut, rawMetadata, coverage)
        → SemgrepAnalyzer::candidates() → list<FindingCandidate>
        → ScanRunner → ScanRecorder (unchanged)
        → Scan / ScanAnalyzerExecution / Finding / FindingOccurrence persisted
```

## Multi-analyzer behavior

`composer-audit` + `npm-audit` + `semgrep` coexist in one
`AnalyzerRegistry`/one scan without one clobbering another's findings —
proven by
`tests/Feature/Audit/Analyzers/Semgrep/SemgrepAuditEndToEndTest.php`
(`composer-audit` + `semgrep`) and manually, live, in Docker (see
[Docker](#docker) below, which also exercises `npm-audit` in the same
run). A Semgrep failure does not remove valid findings from other
analyzers — each analyzer's `AnalyzerResult`/candidates are independent;
the engine's existing `continueOnFailure` (ADR-0009) applies unchanged.

## CLI

No Semgrep-specific CLI logic exists — the existing, generic
`php artisan laradogs:audit {path} [--analyzer=semgrep]` (unchanged since
Phase 4) already works, since `SemgrepAnalyzer` is registered in the same
`AnalyzerRegistry` alongside `composer-audit`/`npm-audit`. Without
`--analyzer`, all three coexist in the same run (see above).

**Phase 6:** `AuditCommand` itself was extended (generically, not with
any Semgrep-specific code) to also normalize and print each analyzer's
`FindingCandidate`s — rule id, severity, category, confidence, file:line,
and message for human output; a richer `findings` array (also including
`recommendation`/`cwe`/`references`) for `--json`. Before this, the CLI
only ever printed an analyzer's own summary/diagnostic count (e.g. "2
finding(s) found"), never the findings themselves, which was not useful
enough for a real manual audit — see
[`../../testing/manual-audit.md`](../../testing/manual-audit.md). This
uses the exact same `ProducesFindingCandidates::candidates()` call
`ScanRunner` itself uses, just without ever invoking `ScanRecorder` — the
command still persists nothing.

## Configuration

`config/laradogs.php` gained one `semgrep` section:

- `binary` (override; `null` triggers PATH resolution).
- `timeout_seconds` (default 60) — the whole-process timeout, enforced by
  `SymfonyProcessRunner`.
- `per_file_timeout_seconds` (default 5) — Semgrep's own `--timeout`
  (passed explicitly for clarity rather than relying on Semgrep's own
  implicit default).
- `max_target_bytes` (default 1,000,000) — Semgrep's own
  `--max-target-bytes`, passed explicitly for the same reason.
- `settings_path` (default `storage_path('app/laradogs/semgrep-settings.yml')`)
  — forced into `SEMGREP_SETTINGS_FILE` (see research above).

No new PHP dependency was added — the bundled ruleset's YAML is parsed
entirely by Semgrep itself (via `--config`); `metadata.cwe`/`references`
reach LaraDogs already-parsed, inside Semgrep's own JSON output, so no
YAML library was ever needed on the PHP side.

No database migration was added — this phase's data fits entirely into
the existing `findings.metadata`/`scan_analyzer_executions.coverage` JSON
columns from Phase 3/3.1.

## Rule catalog (`resources/audit/semgrep/rules/`)

See [`../rules.md`](../rules.md) for the full convention, and
[`../rules/security-rules.md`](../rules/security-rules.md),
[`../rules/quality-rules.md`](../rules/quality-rules.md), and
[`../rules/performance-rules.md`](../rules/performance-rules.md) for what
each rule actually detects. Bundled rules today
(`App\Audit\Analyzers\Semgrep\SemgrepRuleCatalog::RULESET_VERSION =
'2026.09.2'`, bumped from Phase 5's `'2026.09.1'` when Phase 6 added the
9 rules below the first three):

| Rule id                                               | Category      | Confidence | Severity (YAML) | Phase |
| ----------------------------------------------------- | ------------- | ---------- | --------------- | ----- |
| `laradogs.quality.debug.dd-call`                      | Quality       | High       | `WARNING`       | 5     |
| `laradogs.quality.debug.var-dump-call`                | Quality       | High       | `WARNING`       | 5     |
| `laradogs.security.php.eval-usage`                    | Security      | Medium     | `ERROR`         | 5     |
| `laradogs.security.sql.tainted-raw-query`             | Security      | Medium     | `ERROR`         | 6     |
| `laradogs.security.blade.raw-output-tainted`          | Security      | Medium     | `ERROR`         | 6     |
| `laradogs.security.command.tainted-exec`              | Security      | Medium     | `ERROR`         | 6     |
| `laradogs.security.filesystem.tainted-path`           | Security      | Medium     | `ERROR`         | 6     |
| `laradogs.security.redirect.tainted-open-redirect`    | Security      | Medium     | `WARNING`       | 6     |
| `laradogs.security.mass-assignment.request-all`       | Security      | Medium     | `WARNING`       | 6     |
| `laradogs.quality.debug.ray-call`                     | Quality       | High       | `WARNING`       | 6     |
| `laradogs.configuration.debug.app-debug-default-true` | Configuration | High       | `WARNING`       | 6     |
| `laradogs.performance.eloquent.unbounded-all`         | Performance   | Low        | `INFO`          | 6     |

Each rule id is asserted, by
`tests/Unit/Audit/Analyzers/Semgrep/SemgrepRuleCatalogTest.php`, to appear
verbatim in the actual bundled YAML file — a drift check, not a YAML
parser.

**Phase 6's six taint-mode rules** (SQL, command, filesystem, redirect —
plus the generic-mode Blade heuristic and the plain-pattern mass
assignment/performance rules) were the first to use Semgrep's `mode:
taint` — verified, empirically, to be part of the OSS engine (not
Pro-only) and to correctly propagate through assignment, string
concatenation, and string interpolation, not just a literal
argument-in-argument-out match. See
[`../rules/security-rules.md`](../rules/security-rules.md) for the full
per-rule false-positive analysis, including a real, empirically-caught
cross-rule false positive (Semgrep's PHP matcher treats `->` and `::` as
interchangeable when the receiver is a metavariable) that had to be fixed
with `metavariable-regex` restrictions before these rules could ship.

## Docker

Semgrep's own official Docker image (`semgrep/semgrep`) is
Alpine/musl-based and ships a thin Python entrypoint (`/usr/bin/semgrep`,
literally a `#!/usr/bin/python3` script) backed by a full `site-packages`
tree — inspected directly (`docker run --rm --entrypoint sh
semgrep/semgrep:1.176.0 -c 'cat /usr/bin/semgrep; readlink -f /usr/bin/semgrep'`)
before deciding a strategy. Unlike Composer's single-file PHAR, this is
**not portable** to this image's glibc/Debian `bookworm` base by copying
just the binary. The chosen strategy: a dedicated Python virtualenv under
`/opt/semgrep-venv` in the `runtime` stage, entirely isolated from both
the system Python and LaraDogs' own PHP/Node dependencies (never touches
`/app`), built via `python3 -m venv` + `pip install semgrep==${SEMGREP_VERSION}`
— version pinned via a new `SEMGREP_VERSION` build arg (default `1.176.0`,
matching `SemgrepAnalyzer::MIN_SUPPORTED_VERSION`), never `latest` or an
unpinned `pip install semgrep`. Semgrep is only needed by the `runtime`
stage — the `builder` stage has no Python involved in building LaraDogs
itself, so nothing was added there.

**Real, measured image-size impact:** the `runtime` image grew from
**798MB to 1.18GB** (+~382MB) after adding Python 3 + the Semgrep
virtualenv — confirmed via `docker images`, not estimated. This is a real,
accepted cost of this phase (Semgrep is a full static-analysis engine with
its own Python runtime and dependency tree), not something this phase
attempted to optimize away — see [Known limitations](#known-limitations).

**Real Docker validation performed** (a real `docker compose build` + a
running container, non-root):

```
$ docker run --rm --entrypoint sh laradogs-app:latest -c \
    "whoami && composer --version && node --version && npm --version && semgrep --version"
laradogs
Composer version 2.10.3 2026-08-27 13:34:23
v22.23.2
10.9.8
1.176.0
```

```
$ docker run --rm -e APP_KEY=... \
    -v "$(pwd)/tests/Fixtures/semgrep/php-project:/targets/php-project:ro" \
    laradogs-app:latest php artisan laradogs:audit /targets/php-project --analyzer=semgrep
[passed] Semgrep (semgrep)
  2 finding(s) across 1 PHP file(s) scanned (coverage: explicit).
```

A second run without `--analyzer` confirmed `composer-audit` + `npm-audit`

- `semgrep` all execute in the same run with no regression to the other
  two (Composer/npm's own applicability/failure behavior against that
  particular fixture was unchanged from before this phase). The read-only
  (`:ro`) mounted fixture directory was confirmed byte-for-byte unmodified
  after both runs (`find ... -newer Dockerfile` returned nothing).

## Tests

- `tests/Unit/Audit/Analyzers/Semgrep/SemgrepParserTest.php` — clean/
  with-findings/unrecognized-rule-id/mangled-check_id-prefix/
  partial-parsing/benign-skip/dangerous-skip/invalid-rule-config-error/
  malformed JSON, using captured/synthetic fixtures under
  `tests/Fixtures/semgrep/captured-json/`.
- `tests/Unit/Audit/Analyzers/Semgrep/SemgrepCoverageEvaluatorTest.php` —
  the full allowlist decision table (zero errors/skips, benign skips only,
  any error at all, dangerous skip reasons, an unrecognized future skip
  reason).
- `tests/Unit/Audit/Analyzers/Semgrep/SemgrepTargetCollectorTest.php` —
  collects `.php` files, excludes all 8 controlled directories, never
  returns a symlink (even one pointing inside the root), never returns a
  path escaping the root through a symlinked directory, empty results for
  no-match/nonexistent roots.
- `tests/Unit/Audit/Analyzers/Semgrep/SemgrepRuleCatalogTest.php` — the
  bundled YAML file exists, every catalog rule id appears verbatim in it,
  `find()`/`ruleIds()` consistency, the "8-15 rules" size constraint
  (Phase 6 — widened from Phase 5's "2-5 rules" proof-of-vertical range).
- `tests/Feature/Audit/Analyzers/Semgrep/SemgrepAnalyzerTest.php` —
  applicability (PHP detected / not detected), availability (binary
  missing / version-check failure / version too old / available), argv
  safety (`--config`/`--json`/`--verbose`/`--metrics=off`/`--oss-only`,
  explicit file-list targets never a directory, cwd is the rules
  directory, vendor/ exclusion, env forcing/`SEMGREP_APP_TOKEN` never
  forwarded), outcome handling (clean pass with Explicit coverage, empty
  file set with Explicit coverage and semgrep never even invoked, real
  findings normalized correctly, CWE/references passthrough, Unknown
  coverage on any error, fail-closed on non-zero exit / malformed JSON /
  truncated output, timeout reported distinctly, category never invented).
- `tests/Feature/Audit/Analyzers/Semgrep/SemgrepAuditEndToEndTest.php` —
  the full pipeline (real Discovery, real Engine, a fake/scripted
  `ProcessRunner`, real `ScanRunner`/`ScanRecorder`, real persistence),
  the malicious-execution/`SHOULD_NEVER_EXIST` proof, and the
  `composer-audit` + `semgrep` coexistence proof.
- `tests/Feature/Audit/Analyzers/Semgrep/SemgrepFindingLifecycleTest.php`
  — all 5 required lifecycle cases against the real analyzer across
  successive scans (see [Coverage](#coverage-the-first-analyzer-to-use-explicit)
  above).
- `tests/Feature/Audit/Analyzers/Semgrep/SemgrepAuditRealBinaryTest.php` —
  four opt-in tests against a REAL `semgrep` binary, skipped unless
  `LARADOGS_TEST_REAL_SEMGREP=1` is set: real findings against the
  `php-project` fixture (vendor/'s `eval()` never reported), the real
  `.semgrepignore`/`.gitignore` bypass, the real malicious-execution proof,
  and a real scan against a filesystem-read-only target directory
  asserting byte-for-byte-unchanged contents afterward. All four passed
  when run locally against the real, installed `semgrep` 1.176.0. The
  automated suite never requires a real Semgrep binary, network access, or
  Docker to pass.
- `tests/Feature/Audit/Analyzers/Semgrep/SemgrepLaravelRulesRealBinaryTest.php`
  (Phase 6) — the "rule quality gate" for all 9 new Laravel-aware rules:
  for each one, a positive fixture line IS flagged and every negative/safe
  fixture line is NOT, against the REAL `semgrep` binary (never
  `FakeProcessRunner`) — see
  [`tests/Fixtures/semgrep/rules/`](../../../tests/Fixtures/semgrep/rules/)
  for the fixtures and [`../rules/security-rules.md`](../rules/security-rules.md)
  for the false-positive analysis behind each one. Also opt-in, gated by
  `LARADOGS_TEST_REAL_SEMGREP=1`.
- `tests/Feature/Console/AuditCommandTest.php` (Phase 6) — the CLI's own
  findings rendering: human output contains the rule id/file:line/message,
  `--json` output's `findings` array includes `recommendation`, and an
  analyzer with zero findings prints no "Findings" section at all.

### Manual verification performed

All four `SemgrepAuditRealBinaryTest.php` cases, plus all 10
`SemgrepLaravelRulesRealBinaryTest.php` cases (Phase 6), were run locally
(`LARADOGS_TEST_REAL_SEMGREP=1 php artisan test --filter=Semgrep...RealBinaryTest`)
against the real, installed `semgrep` 1.176.0 — all passed. A real
`docker compose build` + running container was used for the Docker
validation described above; all containers/temp directories created for
verification were removed afterward.

## Known limitations

- Only 12 rules exist (3 from Phase 5, 9 from Phase 6) — a small,
  deliberately curated set proving the pattern works, not a comprehensive
  Laravel security scanner. No complete SQL-injection/XSS/CSRF coverage,
  no authorization-bypass detection (explicitly deferred — see
  [`../rules/security-rules.md`](../rules/security-rules.md)'s own
  discussion of why a naive "controller method without `authorize()`"
  rule was rejected as too false-positive-prone), no N+1 detection. The
  real, comprehensive rule library is future work (see
  [`../rules.md`](../rules.md) and [`../static-analysis.md`](../static-analysis.md)).
- Phase 6's taint-mode rules are intraprocedural only — a tainted value
  passed through another function/method before reaching a sink is not
  tracked across that call boundary. See each rule's own "Limitations"
  section in [`../rules/security-rules.md`](../rules/security-rules.md).
- The Blade XSS rule (`laradogs.security.blade.raw-output-tainted`) is a
  textual heuristic (Semgrep `generic` mode has no real dataflow) — it
  only catches a DIRECT request-input call inside the raw-output block,
  not a tainted variable assigned earlier and echoed by name. This is a
  real, documented false-negative gap, not a false-positive risk.
- `MIN_SUPPORTED_VERSION` (`1.176.0`) is conservative by construction, not
  research-backed across a version range: the exact fields this parser
  depends on (`paths.skipped[].reason` requiring `--verbose`, the dual
  severity vocabulary) were verified only against this one installed
  version, unlike Composer's/npm's floors (each tied to a documented
  feature-introduction version). Bump only after re-verifying against a
  newly-tested version.
- The runtime Docker image grew by ~382MB (798MB → 1.18GB) — a real,
  accepted cost of a Python-based static analysis engine, not optimized
  in this phase (no destructive image-slimming attempted under this
  phase's time pressure).
- Coverage is always all-or-nothing across the bundled catalog (Explicit
  over the full rule id list, or Unknown) — there is no per-file or
  per-rule partial-coverage concept; a single `errors[]` entry anywhere in
  a scan downgrades the ENTIRE run's coverage to Unknown, even if only one
  file out of a thousand was affected. This is the safe, conservative
  choice for this phase, not a claim that finer-grained coverage
  wouldn't be valuable future work.
- No resource limits beyond `ProcessRunner`'s own timeout and Semgrep's
  own `--timeout`/`--max-target-bytes` flags — no cgroups, no memory
  ceiling (`--max-memory` is left at Semgrep's own default, unlimited).
