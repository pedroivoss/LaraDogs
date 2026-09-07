# Analyzer: Npm Audit

**Status: Implemented (Phase 4.2; registry/proxy trust hardened in Phase
4.2.1).** The second real analyzer:
`App\Audit\Analyzers\Npm\NpmAuditAnalyzer` runs `npm audit` against a
project's locked npm dependencies and normalizes real security advisories
into `FindingCandidate`s — the same rigor (security, determinism,
defensive parsing, fail-closed, tests, documentation) as
[`composer-audit.md`](composer-audit.md), verified against the real `npm`
CLI rather than assumed. See [`../audit-engine.md`](../audit-engine.md)
for the Engine contract this implements,
[`../../development/process-execution.md`](../../development/process-execution.md)
for the process-execution boundary it runs through, and
[ADR-0011](../../architecture/decisions/ADR-0011-safe-external-process-execution.md)
for the shared safe-process-execution decision (no new ADR was needed for
npm specifically — see [ADR](#29-adr) below).

Deliberately does **not** share an abstract base class with
`ComposerAuditAnalyzer`: the two tools' safety details differ enough
(npm's registry-redirection risk and per-user `.npmrc` credential surface
have no Composer equivalent) that forcing a shared base now would either
leak npm-specific concerns into Composer's analyzer or hide npm-specific
decisions behind generic-looking method names. A small amount of
structural duplication between the two analyzers is accepted deliberately
— see the Phase 4.2 brief's own instruction to prefer that over a
premature shared abstraction.

## Scope

Only `npm audit` — deliberately no `yarn audit`, `pnpm audit`, `bun`,
OSV-Scanner, Trivy, Semgrep, ESLint/TypeScript analyzers, no
`npm install`/`npm ci`/`npm audit fix`. One scanner at a time, per the
same philosophy as Phase 4's Composer slice.

## npm documentation/source researched

Local `npm` (v10.9.7 at research time) + official npm CLI docs +
`npm/cli` GitHub source (`lib/commands/audit.js`,
`lib/utils/audit-error.js`, the vendored `npm-audit-report` package's
`exit-code.js`/reporters) were used — Context7 was not needed for this
research (no real doubt required a lookup there beyond what local
reproduction + official source already settled definitively). Key,
source-verified (not assumed) findings:

- **`npm audit` requires a lockfile.** Per official docs: "By default npm
  requires a package-lock or shrinkwrap in order to run the audit." Both
  `package-lock.json` and `npm-shrinkwrap.json` work identically —
  reproduced directly (a `package-lock.json` renamed to
  `npm-shrinkwrap.json`, nothing else changed, produces byte-identical
  audit output). `yarn.lock`/`pnpm-lock.yaml` are NOT accepted lockfiles
  for `npm audit` — reproduced by confirming npm errors with `ENOLOCK`
  when only a non-npm lockfile is present.
- **Exit codes are fixed 0/1, computed from severity counts against a
  configurable threshold — not a tool-failure signal.** Read directly
  from `npm-audit-report`'s `exit-code.js`: exit `1` iff any
  `metadata.vulnerabilities` severity count at or above `audit-level`
  (default `low`, when the CLI flag isn't passed) is `> 0`. Confirmed live:
  a real audit against a real vulnerable `lodash@4.17.4` returned exit
  `1` with 10 real advisories, correctly treated as `Passed`.
- **A network/registry failure produces a COMPLETELY different, easily
  distinguishable JSON shape** — reproduced by pointing a real audit at
  an unreachable registry: stdout becomes
  `{"message": "...", "error": {...}}`, with no `vulnerabilities`/
  `metadata`/`auditReportVersion` keys at all. A missing-lockfile error is
  different again (`{"error": {"code": "ENOLOCK", ...}}`). Both are exit
  code `1` — **the SAME exit code as "vulnerabilities found."** This is
  why exit code is never used to decide trustworthiness (see
  [Exit-code semantics](#18-exit-code-semantics)).
- **Lifecycle scripts (`preinstall`/`install`/`postinstall`/`prepare`/
  `prepublish`) are never triggered by plain `npm audit`** — confirmed
  both by reading the source (`Audit.exec()` calls `Arborist.audit()`,
  never `.reify()`, which is the only method that runs scripts/writes
  `node_modules`) and by direct reproduction against a fixture whose
  `package.json` declares all five scripts touching a marker file: the
  marker was never created by `npm audit` alone (only by an EARLIER,
  separate `npm install` step used just to generate the lockfile in
  testing — never by the audit command itself). `--ignore-scripts` is
  still always passed, as defense-in-depth, not because it was found
  necessary.
- **`npm audit` never creates `node_modules`, a lockfile, or any other
  target file** — confirmed by running it against a directory containing
  only `package.json` + `package-lock.json` (no `node_modules` at all,
  ever) and observing the directory's contents unchanged afterward, with
  `node_modules` still absent.
- **Config/registry behavior — the central finding of this phase's
  research, see [npm configuration security](#9-npm-configuration-security)
  below.**

## Architecture

Mirrors Composer's shape in the new `App\Audit\Analyzers\Npm` namespace:
`NpmBinaryResolver`, `NpmAdvisory`/`NpmAuditReport` (parsed-report value
objects), `NpmAuditParser`, `NpmAuditAnalyzer` (implements both `Analyzer`
and `ProducesFindingCandidates`). Registered in `AppServiceProvider`
alongside `ComposerAuditAnalyzer` in the same `AnalyzerRegistry`
singleton — no changes needed to `ScanRunner`, `ScanRecorder`,
`FindingIngestor`, or the `laradogs:audit` CLI command, all of which were
already generic over "however many analyzers are registered."

## Applicability

**Applicable**: a valid `package.json` (`ProjectProfile.frontend.node`
detected) **and** an npm-native lockfile
(`ProjectProfile.frontend.npmLockfile` detected — a new Phase 4.2
Discovery field, see below). **Not applicable** when either is missing,
or when the only lockfile present is `yarn.lock`/`pnpm-lock.yaml` (a
different package manager's lockfile — Discovery's `npmLockfile` Detection
is satisfied only by `package-lock.json`/`npm-shrinkwrap.json`, verified
by dedicated Discovery tests using real yarn-only and pnpm-only
fixtures). LaraDogs never runs `npm install`/`npm ci` to create a missing
lockfile — a package.json-without-lockfile project is simply not
applicable, mirroring Composer's identical policy.

### Discovery extension (Phase 4.2)

`App\Audit\Discovery\Profile\FrontendProfile` gained a new
`Detection $npmLockfile` field (analogous to `BackendProfile.composerLock`),
populated by `FrontendInspector` from a new
`NpmManifest::npmLockfileExists` fact. `NpmManifest::detectPackageManager()`
was also fixed to recognize `npm-shrinkwrap.json` as npm (it previously
only checked `package-lock.json`, a real Phase 1 gap this analyzer's
applicability needed closed — a project using shrinkwrap alone would
otherwise have reported `packageManager: null`). This is the only Phase 1
Discovery change this phase needed — everything else about applicability
lives in the analyzer itself, consistent with Composer's
`backend.composer`/`backend.composerLock` pattern.

## Availability / npm version

Resolves the `npm` binary (see [Npm binary resolver](#npm-binary-resolver)
below), then runs a cheap `npm --version` call (never `audit` itself) and
requires `>= 7.0.0`. **7.0.0, not e.g. 6.x**, because the
`auditReportVersion: 2` JSON schema this parser targets — and the "Bulk
Advisory" endpoint it depends on — were introduced in npm 7 (per official
npm CLI docs); npm 6's audit output uses a materially different, older
shape (`advisories`/`actions` keys, no `auditReportVersion` at all) this
parser was never built against and would correctly reject as unparseable
(see [Coverage/schema](#18-exit-code-semantics)) rather than risk
misinterpreting it. `npm --version`'s own output is just the bare version
number (e.g. `10.9.7`) — parsed directly, unlike Composer's
"Composer version X.Y.Z ..." prefixed format.

### Npm binary resolver

`App\Audit\Analyzers\Npm\NpmBinaryResolver` mirrors
`ComposerBinaryResolver`'s exact shape (a small, deliberate duplication,
not a shared base class — see the top of this document): resolves from
`config('laradogs.npm.binary')` (explicit override) or LaraDogs' own PATH
via Symfony's `ExecutableFinder` — **never** `./node_modules/.bin/npm`
from the target, and never a binary path/name read from the target's own
`package.json`.

## npm command

```
[npm, audit, --json, --package-lock-only, --ignore-scripts,
 --registry=<pinned>, --proxy=<pinned-or-false>,
 --https-proxy=<pinned-or-false>, --strict-ssl=true]
```

(No credentials appear in this argv — none are ever passed.)

- `--json` — machine-readable output; schema below.
- `--package-lock-only` — a real, documented `npm audit` parameter
  (confirmed in the CLI's own param list); forces auditing purely from the
  lockfile. Added as defense-in-depth: reproduction showed plain
  `npm audit` already ignores `node_modules` content in favor of the
  lockfile-derived tree even without this flag, so its necessity wasn't
  conclusively provable in isolation — included anyway, at zero cost, the
  same way Composer's `--locked` is always passed.
- `--ignore-scripts` — defense-in-depth (see
  [npm documentation/source researched](#2-npm-documentationsource-researched)
  above: proven unnecessary for the `audit` subcommand specifically, kept
  anyway as a second, redundant layer of the same guarantee).
- `--registry=<pinned>` — **the concrete mitigation against registry
  redirection** — see below.
- `--proxy=<pinned-or-false>` / `--https-proxy=<pinned-or-false>`
  (**Phase 4.2.1**) — always passed, `false` unless an operator has
  explicitly configured a trusted proxy
  (`config('laradogs.npm.proxy')`/`https_proxy`). **The concrete
  mitigation against proxy-based interception** — see
  [npm configuration security](#9-npm-configuration-security) below.
- `--strict-ssl=true` (**Phase 4.2.1**) — always passed explicitly
  (matches npm's own default, but pinned rather than left to inherit
  whatever the target's `.npmrc` might otherwise set) — defense-in-depth
  alongside the proxy pin, since a weakened TLS check is only actually
  exploitable in combination with a proxy/MITM position.

## Lockfile strategy

`package-lock.json` and `npm-shrinkwrap.json` are treated identically
(both satisfy `Detection $npmLockfile`) — verified to produce
byte-identical `npm audit` output. `yarn.lock` and `pnpm-lock.yaml` are
deliberately never treated as npm lockfiles, even though a project using
either might also happen to have stale/vestigial npm lockfile-adjacent
state — Discovery's own `packageManager` inference (pnpm > yarn > npm, in
that priority order when multiple lockfiles somehow coexist) already
existed from Phase 1 and was left unchanged; only the SEPARATE
`npmLockfile` Detection is new. No lockfile at all → `NotApplicable`,
consistent with never running `npm install`/`npm ci` to manufacture
auditable state.

## npm configuration security

**The central research finding of Phase 4.2, refined and hardened in
Phase 4.2.1.** `npm`'s own documentation confirms: "a `.npmrc` file in
the root of the project... will set config values specific to this
project" — read automatically whenever `npm` runs with that directory as
its working directory, with **no flag to disable this**. This means a
target project's own `.npmrc` is read by every `npm audit` invocation
against it. Investigated and reproduced (not merely documented
superficially) what that `.npmrc` can and cannot do — trust boundary:
**registry trust comes only from LaraDogs' own configuration
(`config('laradogs.npm.*')`), never from the audited project.**

- **`audit=false` does NOT suppress `npm audit`.** Reproduced directly: a
  project `.npmrc` setting `audit=false` still produces a full, correct
  audit report when `npm audit --json` is run explicitly. Reading
  `Audit.exec()`'s source confirms it never checks this config key at
  all — `audit=false` only affects the OPTIONAL audit-as-a-courtesy
  behavior during other commands (like `npm install`), not the explicit
  `audit` command.
- **`registry=<attacker-controlled-host>` in the target's `.npmrc` WOULD
  redirect the audit query** (and, by extension, could return a
  fabricated "clean" response from a server the target controls) **if
  left unmitigated.** Mitigated by pinning `--registry=` as an explicit
  CLI flag (npm's documented config precedence: CLI flags > env vars >
  npmrc files — CLI wins). Reproduced side-by-side: the SAME
  hostile-`.npmrc` fixture fails without the flag and succeeds, with
  correct real data, once pinned.
- **Scoped registry overrides (`@scope:registry=...`) — investigated in
  depth this phase, and disproven as a vector for `npm audit`
  specifically.** Read directly from the exact installed npm's own
  source (`@npmcli/arborist/lib/audit-report.js`,
  `AuditReport[_getReport]()`): `npm audit` collects the **entire**
  dependency tree — scoped packages included — into ONE bulk payload
  (`prepareBulkData()`) and POSTs it to exactly ONE registry
  (`options.auditRegistry || options.registry` — i.e. the SAME value
  `--registry=` already pins; `audit-registry` is not even a definable
  npm config key). There is no code path where a scoped package's
  advisory data is looked up against its own scope-specific registry —
  unlike `npm install`, which genuinely does consult scoped registries
  for package resolution, `npm audit` never resolves/fetches packages at
  all, only submits names+versions already known from the lockfile.
  **Reproduced empirically**, not just read from source: a real local
  HTTP server was configured as `@types:registry=http://127.0.0.1:<port>/`
  in a project's `.npmrc` (with a real `@types/lodash` dependency in the
  tree) and a real `npm audit --registry=https://registry.npmjs.org` was
  run against it — the local server's request log stayed empty (the same
  server independently confirmed to work correctly via a direct `curl`).
  **No code change was needed for this vector; it does not need to be
  neutralized because it never applied.**
- **The REAL, previously-unmitigated vector, found and closed this
  phase: `proxy=`/`https-proxy=` in the target's `.npmrc`.** Reproduced
  directly and conclusively: a project `.npmrc` setting
  `https-proxy=http://127.0.0.1:<port>/` (with `--registry=` already
  correctly pinned to the real registry) made a real `npm audit` attempt
  **two** `CONNECT registry.npmjs.org:443` requests through that local
  server — proving the registry pin alone does **not** stop the request
  from being _routed_ through a server the target controls, which could
  intercept, tamper with, or fabricate the response despite the correct
  hostname being requested. **Mitigation, also verified empirically**:
  `--proxy=false --https-proxy=false` (npm's `proxy` config explicitly
  supports `false` as "disable outright", confirmed in its own
  Definition) make the exact same hostile `.npmrc` produce **zero**
  requests to the local server, with the audit succeeding directly
  against the real registry. Trust for a real, legitimate corporate
  proxy comes only from `config('laradogs.npm.proxy')`/`https_proxy`,
  operator-set — never from the target, never from LaraDogs' own ambient
  environment (a CLI flag with `false`/an explicit value always
  overrides both). `--strict-ssl=true` is pinned alongside these as
  defense-in-depth (a weakened TLS check is only practically exploitable
  in combination with a proxy/MITM position, which is now closed).
- **TLS trust material (`cafile`/`cert`/`certfile`/`key`/`keyfile`) —
  the one category left un-neutralized by a flag, so it fails closed
  instead.** `cafile` was confirmed, directly from source
  (`Definition('cafile').flatten()` calling `maybeReadFile(obj.cafile)`),
  to make npm read an **arbitrary file from disk** (not bounded to the
  target project) into TLS trust material. With the proxy vector closed,
  this is no longer combinable with a MITM position to fabricate
  responses — but LaraDogs has not built (and, per this phase's own
  scope, should not build) a way to verify a target-supplied CA bundle
  or client certificate is safe, so `App\Audit\Analyzers\Npm\NpmConfigInspector`
  statically detects the mere PRESENCE of any of these five keys (never
  their values) in the target's own `.npmrc`, and `NpmAuditAnalyzer::run()`
  fails closed with a `UNSAFE_TARGET_NPM_CONFIG` diagnostic — **before
  ever invoking `npm` at all** — rather than attempting to reason about
  whether a particular instance is safe.
- **Credential leakage: a developer's own personal `$HOME/.npmrc`** (which
  may carry real registry auth tokens for legitimately private packages)
  must never reach this subprocess. Mitigated by **always** (not merely
  forwarding if already present) setting `NPM_CONFIG_USERCONFIG` to a
  fixed, LaraDogs-controlled path
  (`config('laradogs.npm.userconfig_path')`, default
  `storage_path('app/laradogs/empty.npmrc')`) — verified empirically that
  npm treats a **nonexistent** userconfig path as simply "no per-user
  config" (not an error), so this file never needs to be pre-created.
  Verified side-by-side with a fake personal `.npmrc` planted at a fake
  `$HOME`: its (non-default) `registry` setting is visible without the
  override and completely neutralized once `NPM_CONFIG_USERCONFIG` points
  elsewhere.
- **A target's own fake auth values (`_auth`/`_authToken`/`username`/
  `_password`)** pose no leak risk to LaraDogs: since `NPM_CONFIG_USERCONFIG`
  is neutralized, no REAL credential is ever loaded for a target's config
  to combine with — a target's own `.npmrc` claiming its own fake token
  for the (correctly pinned) real registry is just the target sending
  its own made-up data back to npmjs.org, which is harmless noise, not a
  LaraDogs-side leak. Never persisted regardless — `NpmAuditParser`
  never reads `.npmrc` at all, and `NpmConfigInspector` reports key
  _names_ only, never values.
- **Global/builtin config files** (`$PREFIX/etc/npmrc`, npm's own built-in
  config) are tied to the trusted, LaraDogs-controlled npm installation
  itself, not to the target or an arbitrary user — not treated as
  untrusted input, the same way LaraDogs already trusts its own resolved
  `composer`/`npm` binaries once resolved via `NpmBinaryResolver`.

**Result:** a real, opt-in test suite
(`tests/Feature/Audit/Analyzers/Npm/NpmAuditRealBinaryTest.php`, gated by
`LARADOGS_TEST_REAL_NPM=1`) runs a genuine `npm audit` against a fixture
whose `.npmrc` simultaneously sets a hostile registry, a hostile proxy
(always-closed port 1, so an unmitigated run would fail rather than
silently succeed), `audit=false`, and a fake auth token — and asserts the
run still succeeds with real advisory data. A SEPARATE, more direct test
opens a real local TCP socket, points a target's `.npmrc` scoped registry
AND proxy at it, runs a real audit, and asserts the socket's accept queue
is empty afterward — literal proof of zero bytes reaching the hostile
endpoint, not just "the end result looked right." A third, non-network
test suite (`NpmConfigInspectorTest.php`,
`NpmAuditAnalyzerTest.php`) proves the `cafile`/`cert`/`certfile`/`key`/
`keyfile` fail-closed path without needing real npm at all.

## Target safety

- `npm install`/`npm ci`/`npm audit fix` are never run.
- `--package-lock-only` and `--ignore-scripts` are always passed (see
  above).
- The `npm` binary is resolved only via LaraDogs' own config/PATH (see
  above) — never `./node_modules/.bin/npm` from the target.
- The registry is always pinned via an explicit CLI flag (see above).
- `NPM_CONFIG_USERCONFIG`/`NPM_CONFIG_CACHE` are always forced to
  LaraDogs-controlled paths (see [Cache/home/temp](#11-cachehometemp)).
- Verified by a real, opt-in test against a **filesystem-read-only**
  target directory copy: the run succeeds, and the target's contents are
  byte-for-byte unchanged (hashed before/after) — no `node_modules`, no
  new/modified lockfile, no marker file from the malicious-scripts
  fixture.

## Cache/home/temp

`NPM_CONFIG_USERCONFIG` and `NPM_CONFIG_CACHE` are **always** set by
`NpmAuditAnalyzer` itself (not merely forwarded through the shared
`process.env_allowlist` the way Composer's `COMPOSER_HOME` is) — a
stronger guarantee given the credential-leakage stakes specific to npm.
Both default to paths under LaraDogs' own `storage_path('app/laradogs/')`
(already covered by `storage/app/.gitignore`'s blanket `*` rule — nothing
created there is ever accidentally committed), which npm creates on
demand (verified empirically: pointing `NPM_CONFIG_CACHE` at a directory
that doesn't exist yet, npm creates it and its subdirectories on first
use, same as it treats a missing `NPM_CONFIG_USERCONFIG` file as "no
config" rather than an error). This works identically locally and in
Docker, with **no Docker-specific environment variable needed** — unlike
Composer's `COMPOSER_HOME`, which Docker sets as a container `ENV` for the
existing allowlist to forward.

## NpmAuditParser

`App\Audit\Analyzers\Npm\NpmAuditParser` — dedicated, defensive, never
mixed into `ProcessRunner`/the analyzer. Requires the top-level shape
`auditReportVersion` (any value, presence only) + `vulnerabilities`
(array) + `metadata.vulnerabilities` (array) — **all three missing or
wrong-typed → `null`, never a partial/best-effort parse.** This single
check is what makes network/registry-error responses AND missing-lockfile
errors AND any older/unrecognized schema (e.g. a synthetic npm 6-style
`advisories`/`actions` fixture, tested directly) all fail the same safe
way: `NpmAuditAnalyzer::run()` reports `Failed`, never `Passed` with zero
findings. See `tests/Unit/Audit/Analyzers/Npm/NpmAuditParserTest.php`.

## Vulnerability normalization

Per package entry in `vulnerabilities`, `via` is a **mixed** array: real
advisory objects (identified by a `source` field) interleaved with plain
package-name **strings** — meta-vulnerability cross-references to another
package's own entry in the same map (e.g. a package "mocha" whose own
`via` is entirely strings like `["debug", "mkdirp"]`, carrying zero real
advisory data of its own, because it's only vulnerable by virtue of
depending on `debug`/`mkdirp`, which each carry their own real advisory
objects separately). **Only object entries become `NpmAdvisory`s; string
entries are skipped entirely** — following them would either duplicate an
advisory already produced from its own real owning entry, or (for a
purely meta-vulnerable package like `mocha` in the example above) attempt
to fabricate a "finding" with no real advisory data behind it at all.
Verified against a real, reproduced transitive-dependency example
(`mocha@3.0.0`'s real dependency tree) and covered by a dedicated parser
test using that exact captured shape.

## Finding granularity

One real advisory object (`via` entry with a `source` field) → one
`FindingCandidate`. A package's `nodes` (the paths where it appears in the
tree) is metadata, not a further identity axis — the same advisory
reached via multiple dependency paths does not produce duplicate
candidates, because the top-level `vulnerabilities` map is already keyed
uniquely by package name (one entry per package, regardless of how many
places in the tree it appears).

## Rule identity

`FindingCandidate.ruleId` is `"{packageName}:{source}"` — e.g.
`lodash:1106900`. `source` (a numeric npm-audit-report advisory id,
confirmed present on every real advisory object reproduced) is very
likely already globally unique on its own, but the package name is folded
in anyway for the same reason as Composer's `{package}:{advisoryId}`
identity: removing any dependency on that numbering scheme's uniqueness
guarantee holding forever. Neither `title` (human-facing, can be edited
upstream) nor array position is used as identity.

## Fingerprint

`Fingerprinter` v1 needed no changes — dependency findings from npm carry
no `filePath`/`codeSnippet`, already tolerated since Phase 3 and
re-confirmed by this phase's own end-to-end test.

## Severity / confidence

npm's own `severity` string (confirmed real values via the exit-code
threshold enum and live reproduction: `info`/`low`/`moderate`/`high`/
`critical`) is mapped directly: `critical→Critical`, `high→High`,
`moderate→Medium`, `low→Low`, `info→Info` (a genuine, documented npm
severity level, mapped to LaraDogs' own `Info` on the same semantic
basis — "informational," not a real problem — rather than treated as
unrecognized), anything else/absent → `Severity::Unknown`. Confidence is
always `Confidence::High`, unconditionally — reflects confidence in the
**match** (npm matched a real advisory against a locked package version;
no heuristic/partial match to hedge against here), never the advisory's
real-world impact (that's severity's job) — documented policy, identical
reasoning to `composer-audit`.

## Metadata / fixAvailable

Preserved in `FindingCandidate.metadata` (redacted via the existing
`EvidenceRedactor` before persistence, unchanged from Phase 3):
`package_name`, `source`, `is_direct` (direct vs. transitive — npm's own
`isDirect` field), `cwe` (list), `range` (this specific advisory's
affected range), `package_range` (the package-level aggregated range),
`fix_available` (`bool` or `{name, version, is_semver_major}` — passed
through as-is), `nodes` (dependency paths, bounded by whatever npm itself
returns). `cve` stays `null`: npm's schema does not surface a CVE
identifier directly (only a GHSA-style `url` and a `cwe` list) — never
derived/guessed from the URL. `references` carries the advisory's `url`
when present.

**`fixAvailable` is informational only.** LaraDogs never runs
`npm audit fix`/`npm audit fix --force`/`npm install`/`npm update` — this
phase is an analyzer, not an auto-fixer. Verified by a dedicated test
asserting `fix` never appears in any argv passed to `ProcessRunner`.

## Exit-code semantics

Deliberately never gated on — see
[npm documentation/source researched](#2-npm-documentationsource-researched)
above: exit `1` means "a vulnerability at or above the default `low`
threshold was found," not "the analyzer failed," and (unlike Composer)
exit `1` is **also** what a registry/network failure or a missing
lockfile produces — the exact same code as a normal vulnerable-but-healthy
run. Trustworthiness is decided **entirely** by whether the JSON has the
expected shape (see `NpmAuditParser` above), never by exit code.

## Network / registry failures

A registry/network failure produces `{"message": ..., "error": {...}}` —
no `vulnerabilities`/`metadata`/`auditReportVersion` keys — which
`NpmAuditParser` rejects the same way it rejects any other malformed
shape. **There is no separate "unreachable registry" JSON signal to check
first, unlike Composer's `unreachable-repositories` key** — npm's failure
mode is all-or-nothing (a full valid report, or a hard, differently-shaped
error), which is actually simpler to handle safely than Composer's
partial-failure case: `run()` doesn't need a dedicated branch for this,
the generic "parser returned null → Failed" path already covers it
correctly.

## Coverage

Always `AnalyzerCoverage::unknown()` — same reasoning as `composer-audit`
(see that analyzer's own
[dependency coverage research](composer-audit.md#dependency-coverage-research-phase-41)):
npm's audit model has no "rules executed" universe, regardless of how
thoroughly the registry/proxy trust boundary is hardened (Phase 4.2.1)
— closing a trust gap is not evidence that a "rules executed" universe
suddenly exists to declare `Explicit`/`Full` from. Per the explicit
standing instruction ("if in doubt, keep `Unknown` — false unresolved is
preferable to false resolved"), npm-sourced findings do not auto-resolve
in this version either.

## FindingCandidate integration

Reuses `App\Audit\Findings\Ingestion\ProducesFindingCandidates` and
`ScanRunner` exactly as `composer-audit` does — no second persistence
pipeline. `NpmAuditAnalyzer` implements both `Analyzer` and
`ProducesFindingCandidates`, living in the same outer
`App\Audit\Analyzers\*` namespace pattern.

## Multi-analyzer behavior

`AnalyzerRegistry` already enforced unique analyzer ids (a Phase 2
guarantee, unchanged) — `composer-audit` and `npm-audit` register under
distinct ids with no collision. A dedicated test suite,
`tests/Feature/Audit/MultiAnalyzerCoexistenceTest.php`, proves: both
analyzers are applicable to the same Laravel-full-stack fixture
(`laravel-inertia-react-ts`, which already had both `composer.lock` and
`package-lock.json`); both run and persist Findings independently in one
Scan; and — critically — one analyzer reporting `Failed` (a malformed
Composer response, in the test) does not corrupt, block, or otherwise
affect the other analyzer's `Passed` result and persisted Findings
(`continueOnFailure` defaults to `true`, a Phase 2 guarantee also
unchanged).

## End-to-end pipeline

`tests/Feature/Audit/Analyzers/Npm/NpmAuditEndToEndTest.php` — a
synthetic npm project (reusing the `npm-malicious-scripts` Discovery
fixture) → real Discovery → real `NpmAuditAnalyzer` → a fake/captured
`ProcessRunner` result → real `AuditEngine` → real `FindingCandidate`
normalization → real `ScanRunner`/`ScanRecorder` → real persistence.
Confirms `Scan`/`ScanAnalyzerExecution`/`Finding`/`FindingOccurrence`
creation, correct severity/metadata/rule identity, and — the target's own
`preinstall`/`install`/`postinstall`/`prepare`/`prepublish` scripts never
running, and no `node_modules` ever created.

## CLI

The existing `php artisan laradogs:audit {path} [--json] [--analyzer=...]`
command required **zero changes**: it was already generic over "every
analyzer in the container-bound `AnalyzerRegistry`." `--analyzer=npm-audit`
scopes to just this analyzer (via the same temporary single-analyzer
registry construction already used for `--analyzer=composer-audit`);
omitting `--analyzer` runs every applicable, available analyzer
registered — confirmed live, in Docker, against a fixture with both a
Composer and an npm dimension: `composer-audit` and `npm-audit` both ran
and both reported `passed` in the same invocation.

## Docker

**Node/npm added to the `runtime` stage** (previously Node only existed
in the discarded `builder` stage, for the frontend build).
`ComposerAuditAnalyzer::MIN_SUPPORTED_VERSION`-style reasoning doesn't
carry over directly to Node/npm's install strategy: unlike Composer's
single-file PHAR (reused via one `COPY --from=builder`), npm is not one
file — `/usr/bin/npm` is a thin wrapper around a full
`/usr/lib/node_modules/npm/` tree, so "copy just the binary" doesn't work
the same way. Node/npm are instead **installed directly in `runtime`**,
reusing the SAME `NODE_VERSION=22` build ARG the `builder` stage already
used (not a new, separate version knob) via the identical NodeSource
setup-script + apt mechanism, with the transient `gnupg` dependency
purged in the same layer once `nodejs` is installed. This is the same
level of version-pin precision this Dockerfile already uses for PHP
(`PHP_VERSION=8.3` — a pinned major/minor line, not an exact patch), not
`latest`.

Verified with a real `docker compose build` + a running (non-root,
healthy) container:

- `node --version` → `v22.23.2`; `npm --version` → `10.9.8` (both above
  this analyzer's `>= 7.0.0` floor).
- `composer --version` → `2.10.3` — **confirmed no Composer regression**
  from adding Node/npm to the same stage.
- `php artisan laradogs:audit <fixture> --json --analyzer=npm-audit` →
  `AVAILABLE` + `Passed`, against a **read-only-mounted** (`:ro`) fixture
  with both a Composer and an npm dimension; an explicit `touch` inside
  the mount failed with "Read-only file system."
  `--analyzer=composer-audit` against the same fixture still works
  (regression-verified); omitting `--analyzer` runs and correctly reports
  both.
- npm's cache was confirmed to land at
  `/app/storage/app/laradogs/npm-cache/...` (auto-created on demand, zero
  Dockerfile changes needed for this — see
  [Cache/home/temp](#11-cachehometemp)) — never inside `/app`'s own
  tracked code, never inside the target.
- **Image size impact**: approximately **+229MB** (569MB → 798MB) from
  adding a full Node.js + npm runtime. Not micro-optimized — this phase
  did not attempt e.g. a slimmer Node distribution or npm-specific
  stripping, per its own explicit "don't over-optimize prematurely"
  scope — but deliberately did NOT copy the LaraDogs frontend's own
  `node_modules`, the `builder` stage's npm cache, Node source, or any
  other unnecessary build-time artifact into `runtime`; only the apt
  `nodejs` package itself was added.
- All test containers, volumes, and temp directories created for this
  verification were removed afterward; nothing was left running.

## Tests

- `tests/Unit/Audit/Analyzers/Npm/NpmAuditParserTest.php` — clean, direct
  vulnerability (multiple real advisories), transitive/meta-vulnerability
  (mixed `via` array, real captured `mocha@3.0.0` shape), malformed,
  truncated, registry-error shape, missing-lockfile-error shape,
  unrecognized/older schema shape, missing optional fields, an object
  `via` entry without a `source`, non-array `vulnerabilities`, missing
  `metadata.vulnerabilities`.
- `tests/Feature/Audit/Analyzers/Npm/NpmAuditAnalyzerTest.php` —
  applicability (package-lock.json / npm-shrinkwrap.json / no lockfile /
  yarn-only / pnpm-only / non-npm project), availability (binary missing
  / version-check failure / version too old / available), argv safety
  (`--package-lock-only --ignore-scripts --registry=`, real cwd),
  `NPM_CONFIG_USERCONFIG`/`NPM_CONFIG_CACHE` always forced, outcome
  handling (clean pass, vulnerabilities with exit code 1 still `Passed`,
  transitive-vs-meta-vulnerability granularity, malformed/registry-error/
  unrecognized-schema JSON all fail closed, timeout, truncated output
  fails closed, `fixAvailable` preserved but never executed, coverage
  always `Unknown`) — using the shared
  `tests/Support/Process/FakeProcessRunner.php`. **Phase 4.2.1** added:
  `--proxy=false --https-proxy=false --strict-ssl=true` always present by
  default; an operator-configured trusted proxy used instead when set;
  `UNSAFE_TARGET_NPM_CONFIG` fail-closed on a `cafile`-declaring `.npmrc`
  fixture (`npm-unsafe-npmrc`), asserting `npm` is never even invoked for
  the audit step in that case; a normal project with no `.npmrc` is
  unaffected.
- `tests/Unit/Audit/Analyzers/Npm/NpmConfigInspectorTest.php`
  (**Phase 4.2.1**) — no `.npmrc` → empty; all keys already neutralized
  by flags (registry, scoped registry, audit, proxy, cache, userconfig,
  auth) → NOT flagged (verifies the inspector doesn't duplicate what
  flags already handle); `cafile`/`cert`/`certfile`/`key`/`keyfile` in
  plain and registry-scoped (`//host/:keyfile=`) form → flagged;
  case-insensitivity; values never returned; comments/blank lines
  ignored; duplicate keys deduplicated; oversized `.npmrc` (> 64KB) →
  `NpmConfigTooLargeException`, not a partial scan.
- `tests/Feature/Audit/Analyzers/Npm/NpmAuditEndToEndTest.php` — the full
  pipeline, described above.
- `tests/Feature/Audit/Analyzers/Npm/NpmAuditRealBinaryTest.php` — 5
  opt-in tests against a REAL `npm` binary, skipped unless
  `LARADOGS_TEST_REAL_NPM=1` is set: a plain real-audit run; a real
  filesystem-read-only-target run with `NPM_CONFIG_CACHE` outside it; the
  malicious-lifecycle-scripts fixture (confirms the marker is never
  created); the malicious-`.npmrc` mitigation proof (hostile registry,
  hostile proxy on an always-closed port, `audit=false`, fake token —
  still returns a real, correct, `Passed` result); and (**Phase 4.2.1**)
  the decisive **security regression test**: a real local TCP socket
  (`stream_socket_server`) that a target's `.npmrc` scoped registry AND
  proxy both point at, with a real audit run against it, asserting the
  socket's accept queue is empty afterward — the exact scenario that, in
  this phase's own research, was confirmed to reach a local listener
  (`CONNECT registry.npmjs.org:443`, twice) under the original Phase 4.2
  code, and does not reach it at all under the hardened code.
- `tests/Feature/Audit/MultiAnalyzerCoexistenceTest.php` — Composer + npm
  coexistence, described above; re-run in Phase 4.2.1 to confirm no
  Composer regression from the new npm-specific flags/inspector.
- `tests/Unit/Audit/Discovery/ProjectDiscoveryTest.php` gained 5 new
  tests for `FrontendProfile.npmLockfile` (package-lock.json,
  npm-shrinkwrap.json, yarn-only, pnpm-only, package.json-without-lock).
- No changes were needed to `SymfonyProcessRunner`'s own test suite — npm
  did not reveal any genuine new generic `ProcessRunner` requirement
  beyond what Composer's tests already covered.

## Dependencies

None added — no PHP or npm package was installed to parse `npm audit`'s
JSON output (plain `json_decode()`, exactly like `ComposerAuditParser`).
`npm` itself is invoked purely as an external tool through `ProcessRunner`,
the same way `composer` is.

## Documentation

Created this file. Updated:
`docs/auditing/findings-lifecycle.md`,
`docs/auditing/audit-engine.md`, `overview.md`,
`docs/architecture/components.md`, `data-flow.md`,
`docs/development/docker.md`, `docs/roadmap/roadmap.md`, `docs/README.md`,
`README.md`, `CHANGELOG.md`. `composer-audit.md` itself was left
unchanged — nothing shared enough emerged to warrant editing Composer's
own document (the two analyzers' docs cross-reference each other instead
where relevant, e.g. the coverage section above).

## ADR

**No new ADR.** ADR-0011 ("Safe External Process Execution") already
covers the general safe-process-execution decision this analyzer builds
on; nothing discovered this phase (npm's own registry/config security
model, its lockfile/schema specifics) is a new, generalizable
architectural decision beyond what that ADR and `composer-audit.md`
already establish — it's analyzer-specific detail, documented here
instead, per the explicit "don't create an ADR per scanner" instruction.

## MCP usage

Context7 was not invoked this phase — local reproduction against the
real, installed `npm` CLI plus its own official documentation and GitHub
source were sufficient to resolve every point of genuine doubt (schema,
exit codes, config precedence) without needing a second source. GitHub
MCP and Playwright were not needed.

## Known limitations

- Coverage is always `Unknown` — npm findings do not auto-resolve yet
  (same policy, same reasoning as `composer-audit`).
- **Scoped registry overrides are a non-issue for `npm audit`** (verified
  by source and reproduction, Phase 4.2.1 — see
  [npm configuration security](#9-npm-configuration-security)) — recorded
  here explicitly so this isn't mistaken for an oversight; there is
  nothing further to neutralize.
- **Private/enterprise registries are not supported as a first-class
  feature.** A LaraDogs-controlled trusted registry
  (`config('laradogs.npm.registry')`) is fully supported (that's the
  default `https://registry.npmjs.org` itself). A project-controlled
  custom registry (Artifactory, Verdaccio, GitHub Packages, Nexus, or
  any other private registry the TARGET's own `.npmrc` points at) is
  currently indistinguishable from a hostile one and is neutralized the
  same way (the pinned registry is used instead). This is correct for
  V1 (trust must come from LaraDogs' configuration, never the target),
  but means a project that genuinely depends on a private registry for
  its packages will be audited against the public registry instead,
  which may report packages as "not found" rather than truly failing —
  operators who need this can set `config('laradogs.npm.registry')` to
  their own trusted private registry (no target-supplied override is
  ever honored). Full multi-registry / per-project trusted-registry
  configuration is deliberately not built this phase — see
  [Deferred items](#36-deferred-items).
- **TLS trust material (`cafile`/`cert`/`certfile`/`key`/`keyfile`)
  presence in a target's `.npmrc` always fails the scan closed**,
  regardless of whether that specific instance would have been
  harmless — no attempt is made to distinguish a legitimate corporate
  CA bundle from a malicious one; see
  [npm configuration security](#9-npm-configuration-security).
- Workspaces (`package.json` `"workspaces"`) are not specifically handled
  or tested this phase — `npm audit` runs at the project root as
  discovered; multi-workspace-aware auditing (`--workspaces`,
  per-workspace filtering) is deferred, not silently broken (untested is
  not the same as known-broken, but it is also not verified).
- No dependency-deprecation/abandoned-package handling exists for npm
  (npm's `deprecated` package metadata is a separate concept from
  security advisories, deliberately not conflated — matching Composer's
  own abandoned/vulnerability separation, but npm's side of that concept
  isn't implemented at all yet, not even as a diagnostic).
- Docker image size grew by roughly +229MB for a full Node.js + npm
  runtime — not optimized this phase.

## Deferred items

`yarn audit`, `pnpm audit`, `bun`, OSV-Scanner, Trivy, Semgrep, ESLint/
TypeScript analyzers, PHPStan/Larastan-against-target,
Pest/PHPUnit-against-target, Laravel/React/Vue-aware rules, dashboard,
MCP server, Git monitoring, GitHub Action, auto-fix, workspace-aware
auditing, npm dependency-deprecation findings.

**Future: operator-provided private-registry credentials.** Not built
this phase, but the shape is already anticipated: a future
`config('laradogs.npm.registry')` (or a small per-registry extension of
it) paired with an operator-provided, LaraDogs-side credential — never a
credential read from or supplied by the audited repository. The
`--proxy=`/`--https-proxy=` operator-override mechanism added in Phase
4.2.1 (`config('laradogs.npm.proxy')`/`https_proxy`, defaulting to
forced-off) is the same pattern a future private-registry auth mechanism
should follow.
