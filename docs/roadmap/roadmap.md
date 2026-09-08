# Roadmap

## Phases

| Phase | Name                                    | Status                                                                                                                                                                               |
| ----- | --------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 0     | Discovery / Architecture / Bootstrap    | **Complete**                                                                                                                                                                         |
| 1     | Project Discovery (stack detection)     | **Complete**                                                                                                                                                                         |
| 2     | Audit Engine Foundation                 | **Complete**                                                                                                                                                                         |
| 3     | Finding Domain + Persistence            | **Complete**                                                                                                                                                                         |
| 4     | Security / Dependency Scanners          | **In progress** (`composer audit` + `npm audit` done; a small, 2-5-rule Semgrep foundation done — see below; OSV-Scanner/Trivy and a comprehensive Semgrep rule library not started) |
| 5     | Bug / Quality Analysis                  | Not started                                                                                                                                                                          |
| 6     | Performance Analysis                    | Not started                                                                                                                                                                          |
| 7     | Dashboard                               | Not started                                                                                                                                                                          |
| 8     | History / Comparison / Quality Gates    | Not started                                                                                                                                                                          |
| 9     | MCP                                     | Not started                                                                                                                                                                          |
| 10    | Authentication / MCP Credentials        | Not started                                                                                                                                                                          |
| 11    | Git Integration / Continuous Monitoring | Not started                                                                                                                                                                          |
| 12    | CI / GitHub Action                      | Not started                                                                                                                                                                          |
| 13    | Hardening / Release                     | Not started                                                                                                                                                                          |

No changes were made to this phase list during Phase 0 — the brief's
ordering (foundation → discovery → engine → domain model → scanners →
analysis → surfaces → history → MCP → auth → monitoring → CI → hardening)
is a sound dependency order and nothing encountered during bootstrap
argued for reshuffling it.

## What Phase 0 actually delivered

See the Phase 0 report for the full account. In short: a working Laravel
13 + React/Inertia application (official starter kit), SQLite, Pest,
Docker Compose for local self-hosting, and the documentation/ADR set this
file lives in. No audit-domain code.

## What Phase 1 actually delivered

The Project Discovery Core (`app/Audit/Discovery/`): a static,
evidence-based stack detector (Laravel/Blade/Livewire/Inertia/React/Vue/
TypeScript/testing tools/Docker/CI/database driver hints), a normalized
`ProjectProfile` with explicit `detected`/`not_detected`/`unknown`/
`invalid` states, a `laradogs:inspect` CLI command (human-readable and
`--json` output), 14 synthetic fixtures, 26 tests (including a dedicated
no-code-execution guarantee test), and
[`project-discovery.md`](../auditing/project-discovery.md) +
[ADR-0008](../architecture/decisions/ADR-0008-static-project-discovery.md).
No scanners, no `Finding`/`Scan` model, no persistence — see the Phase 1
report for the full account.

## What Phase 2 actually delivered

The Audit Engine foundation (`app/Audit/Engine/`): the `Analyzer`
contract (`applicability()`/`availability()`/`run()`), `AnalyzerId`/
`AnalyzerCategory`, an `AnalyzerRegistry` with a duplicate-id guard and
deterministic ordering, `AuditPlan`/`AuditPlanItem` (built without
executing anything), execution with exception-safety and a real
`continue_on_failure` fail-fast path, `AuditRunResult` normalizing every
outcome, and a `ProcessRunner` contract (no implementation) recording the
future real-process-execution boundary. 31 tests (including dedicated
no-target-execution and no-shell-execution guarantee tests) against
synthetic analyzers only, plus
[`audit-engine.md`](../auditing/audit-engine.md) +
[ADR-0009](../architecture/decisions/ADR-0009-audit-engine-foundation.md).
No real scanner integration, no `Finding` model, no persistence, no CLI
(deliberately — see the Phase 2 report) — see the Phase 2 report for the
full account.

## What Phase 3 actually delivered

The Finding domain and persistence (`app/Audit/Findings/`,
`app/Models/Audit/`): `Project`/`Scan`/`ScanAnalyzerExecution`/`Finding`/
`FindingOccurrence`/`FindingStatusHistory` migrations (portable across
SQLite/MySQL/MariaDB/PostgreSQL), a versioned (`v1`), line-number-
independent `Fingerprinter`, the `FindingCandidate` normalization DTO, a
centralized `FindingLifecycleService` (reason-required suppressions,
append-only history), `FindingIngestor` (find-or-create + occurrence +
reopen-on-regression, suppressed statuses never auto-reverted),
`FindingReconciler` (auto-resolution only when the owning analyzer
completed `Passed` this scan — never on failure/timeout/unavailable/not
run), a conservative `EvidenceRedactor`, and `ScanRecorder` tying Phases
1+2+3 together end-to-end. 49 tests (including dedicated
no-target-execution and auto-resolution-safety tests) against synthetic
candidates only, plus
[`findings-lifecycle.md`](../auditing/findings-lifecycle.md) +
[ADR-0010](../architecture/decisions/ADR-0010-finding-identity-occurrences-and-lifecycle.md).
No real scanner integration, no dashboard, no MCP server — see the Phase
3 report for the full account.

## What Phase 4 actually delivered

The first real analyzer, end to end (`app/Audit/Analyzers/Composer/`,
alongside the first real `App\Audit\Engine\Process\ProcessRunner`
implementation, `SymfonyProcessRunner`): `ComposerAuditAnalyzer` runs
`composer audit --locked --format=json --no-plugins --no-scripts` against
a project's locked dependencies via a real, argv-only, env-allowlisted,
output-capped, timeout-enforced subprocess boundary — never mutating the
target, never running its plugins/scripts, never trusting its own
`composer` binary claim. A dedicated `ComposerAuditParser` normalizes
Composer's real JSON schema (verified against the `composer/composer`
source, not assumed) into advisories/abandoned-packages/
unreachable-repositories, failing closed (never a false-clean scan) on
malformed/truncated output or an unreachable advisory database. Advisory
rule identity is `package_name:advisoryId` (stable, deterministic);
`Severity::Unknown` was added for advisories the source itself doesn't
rate; coverage is always declared `Unknown` (Composer has no "rules
executed" universe to declare `Explicit`/`Full` from — documented
limitation, not a gap: Composer findings don't auto-resolve yet). A new
`ProducesFindingCandidates` interface (in `Findings\Ingestion`, not
Engine) and `ScanRunner` orchestrator connect a real analyzer to Phase 3's
existing persistence without Engine ever depending on Findings. A
`laradogs:audit {path}` CLI prints one real audit run (no persistence).
45 new tests (206 total; 205 passing + 1 opt-in real-network test skipped
by default) — synthetic PHP-script fixtures for the real `ProcessRunner`
(including a literal shell-metacharacter argv-injection proof), synthetic
Composer JSON fixtures for the parser/analyzer, and one full end-to-end
pipeline test — plus
[`analyzers/composer-audit.md`](../auditing/analyzers/composer-audit.md),
[`../development/process-execution.md`](../development/process-execution.md),
and [ADR-0011](../architecture/decisions/ADR-0011-safe-external-process-execution.md).
No other scanner (`npm audit`, Semgrep, OSV-Scanner, Trivy, PHPStan/
ESLint/Pest-against-target), no dashboard, no MCP server, no Git
monitoring, no GitHub Action, no correlation across scanners, no
Laravel-aware rules — see the Phase 4 report for the full account.

## What Phase 4.1 actually delivered

Docker deployment hardening for `composer-audit`: the official runtime
image previously shipped without a `composer` binary at all (a known
Phase 4 gap) — fixed by reusing the pinned Composer binary from the
`builder` stage (`COPY --from=builder`, one image pull, not two) plus a
fixed, LaraDogs-controlled `COMPOSER_HOME`
(`/home/laradogs/.composer`, set via a container `ENV`, forwarded through
the existing `process.env_allowlist` with zero analyzer code changes).
Verified with a real `docker compose build` + a running (non-root,
healthy) container, including a genuine **read-only-mounted** (`:ro`)
target audit. Separately, researched (but did not implement) whether
`composer-audit`'s coverage could safely move beyond `Unknown` — found,
by direct reproduction against the real `composer` binary, that a
target's own `composer.json` can disable Packagist entirely
(`"repositories": {"packagist.org": false}`) and receive a perfectly
clean, valid, exit-0 audit result even against a real, known-vulnerable
locked package version. This rules out `Full` coverage (not just
"insufficient evidence yet") and additionally confirmed
`ignore-unreachable` policy settings do NOT suppress the
`unreachable-repositories` failure signal already relied on. Coverage
stayed `AnalyzerCoverage::unknown()` — no code change. See the Phase 4.1
report for the full account,
[`../development/docker.md`](../development/docker.md#composer-in-the-runtime-image),
and
[`analyzers/composer-audit.md`](../auditing/analyzers/composer-audit.md#dependency-coverage-research-phase-41).

## What Phase 4.2 actually delivered

The second real analyzer, `App\Audit\Analyzers\Npm\NpmAuditAnalyzer`,
reusing `SymfonyProcessRunner` unchanged: runs
`npm audit --json --package-lock-only --ignore-scripts --registry=<pinned>`
against a project's locked npm dependencies. A dedicated `NpmAuditParser`
normalizes npm's real `auditReportVersion: 2` schema (verified against
the real `npm` CLI and its own GitHub source, not assumed), extracting
only genuine advisory objects from each package's mixed `via` array
(never fabricating findings from plain-string meta-vulnerability
cross-references) and failing closed on malformed output, an unrecognized
schema, or npm's distinctly-shaped registry/network-error response — none
of which share npm's own non-zero-exit-means-failure ambiguity (a
registry failure and "vulnerabilities found" both exit `1`; only JSON
shape decides trust). Discovery (Phase 1) gained a new
`FrontendProfile.npmLockfile: Detection` field (and a
`npm-shrinkwrap.json` recognition fix) so applicability can require an
npm-native lockfile specifically, never confusing `yarn.lock`/
`pnpm-lock.yaml` for one. The phase's central finding: a target's own
`.npmrc` (read automatically, unavoidably) can redirect the audit
registry — mitigated by always pinning `--registry=` as an explicit CLI
flag (npm's own documented config precedence puts CLI flags above
`.npmrc` files), verified against a real fixture whose `.npmrc`
simultaneously sets a hostile registry, `audit=false`, and a fake auth
token, with a real `npm audit` run still returning correct data
unaffected. `NPM_CONFIG_USERCONFIG`/`NPM_CONFIG_CACHE` are always forced
to LaraDogs-controlled paths under its own `storage_path()` — a developer's
personal `$HOME/.npmrc` credentials can never reach this subprocess, in
Docker or locally, with zero Docker-specific configuration needed for it.
Coverage stays `AnalyzerCoverage::unknown()`, same reasoning as Composer.
Node/npm were added to the Docker `runtime` stage (installed directly,
not copied — verified no Composer regression, +~229MB image size). The
existing `laradogs:audit` CLI and `ScanRunner`/`ScanRecorder`/
`FindingIngestor` pipeline needed zero changes to support a second
analyzer; a dedicated multi-analyzer test confirms `composer-audit` and
`npm-audit` coexist deterministically in one registry/scan, with one
analyzer's failure never affecting the other's result. 51 new tests (260
total; 254 passing + 6 opt-in real-network tests skipped by default). No
other scanner (`yarn audit`, `pnpm audit`, `bun`, Semgrep, OSV-Scanner,
Trivy, ESLint/TypeScript analyzers), no dashboard, no MCP server, no
correlation across scanners, no Laravel-aware rules, no ADR (npm's
findings extend ADR-0011's existing scope, not a new architectural
decision) — see the Phase 4.2 report for the full account and
[`analyzers/npm-audit.md`](../auditing/analyzers/npm-audit.md).

## What Phase 5 actually delivered

The third real analyzer, and the first Static Application Security
Testing (SAST) one: `App\Audit\Analyzers\Semgrep\SemgrepAnalyzer`, reusing
`SymfonyProcessRunner` unchanged. Proves the Discovery → Engine →
`SemgrepAnalyzer` → safe process execution → Semgrep → Semgrep JSON →
`SemgrepParser` → `FindingCandidate` → Finding persistence/lifecycle
vertical end-to-end, with a deliberately small, 3-rule bundled ruleset
(`dd()`/`var_dump()` left in code, `eval()` usage) rather than a
comprehensive Laravel-aware library — see
[`../auditing/static-analysis.md`](../auditing/static-analysis.md) and
[`analyzers/semgrep.md`](../auditing/analyzers/semgrep.md) for the full
account.

The phase's central finding, reproduced empirically: pointing Semgrep at
a target DIRECTORY lets the target's own `.semgrepignore` hide a
genuinely-vulnerable file from analysis with no error signal at all —
mitigated by never doing that: a new `SemgrepTargetCollector` performs
LaraDogs' own bounded, symlink-rejecting, realpath-contained file walk and
passes every collected file as an explicit `semgrep scan` argv target
instead, verified to bypass `.semgrepignore`/`.gitignore` regardless of
what either file says — recorded as
[ADR-0012](../architecture/decisions/ADR-0012-trusted-static-analysis-rules.md),
the new rule-source-trust decision this phase required. A second research
finding shaped rule identity: Semgrep's own `check_id` embeds a mangled
form of the `--config` path's directory unless invoked with a bare
filename from that file's own directory as cwd — `SemgrepParser` matches
`check_id` against the known catalog rather than ever trusting it
verbatim, regardless of which prefixing form occurs. `semgrep`
is the first analyzer to genuinely use `AnalyzerCoverage::Explicit`
(Composer/npm's dependency-advisory model has no "rules executed"
universe to declare it from) — `SemgrepCoverageEvaluator` declares it only
when a run reported zero operational errors/warnings and no non-benign
skipped files, conservatively falling back to `Unknown` the moment
there's any doubt; all 5 required lifecycle cases (verified resolution,
removed rule, failed analyzer, unknown coverage, regression) are proven
against the real analyzer across successive scans. Semgrep was added to
the Docker `runtime` stage via an isolated Python virtualenv (its official
image is Alpine/musl-based and not binary-portable to this image's
glibc/Debian base) — verified no Composer/npm regression, +~382MB image
size (798MB → 1.18GB). The existing `laradogs:audit` CLI and
`ScanRunner`/`ScanRecorder`/`FindingIngestor` pipeline needed zero changes
to support a third analyzer; a dedicated test confirms `composer-audit`
and `semgrep` coexist deterministically in one registry/scan (also
verified manually with all three analyzers together in a real Docker
container), with one analyzer's failure never affecting another's result.
69 new tests (329 total; 318 passing + 11 opt-in real-network/real-binary
tests skipped by default). No comprehensive Laravel-aware rule library, no
OSV-Scanner/Trivy/ESLint/PHPStan-against-target, no auto-fix/AI
remediation, no dashboard, no MCP server, no correlation across scanners
— see [`analyzers/semgrep.md`](../auditing/analyzers/semgrep.md) for the
full account.

## Deferred items (noticed during Phase 0, intentionally not built)

These are candidate improvements or gaps spotted while bootstrapping.
Recording them here instead of building them now, per the "avoid scope
creep" instruction for this phase.

- **Disable public self-registration by default.** The starter kit's
  `/register` route is open. For a security tool, this should likely be
  invite-only or admin-provisioned before real findings exist behind it.
  → Phase 10.
- **Server deployment profile** (Redis, queue workers, scheduler, reverse
  proxy, multi-project). → Tracked across Phase 3 (multi-project schema),
  Phase 8 (workers for scan execution), and a dedicated Docker Compose
  profile likely alongside Phase 11/13. Database vendor choice for this
  profile — SQLite, MySQL, MariaDB, or PostgreSQL — is independent of it;
  see [ADR-0007](../architecture/decisions/ADR-0007-database-agnostic-persistence.md).
- **Scanner sandboxing implementation** (containers-per-run vs. restricted
  subprocess). Constraint recorded in ADR-0004; Phase 4 resolved this for
  the process-boundary level (argv-only, env-allowlisted, output-capped
  subprocess — [ADR-0011](../architecture/decisions/ADR-0011-safe-external-process-execution.md))
  without introducing containers-per-run; revisit if a future scanner
  needs stronger isolation than a controlled subprocess provides.
- **Dashboard visual identity.** The starter kit's default branding/welcome
  page was left untouched — reskinning is a Phase 7 concern, not a Phase 0
  one.
- **Laravel Boost** (AI-agent developer tooling for this codebase itself)
  was deliberately not installed in Phase 0 (`--no-boost`). Worth
  revisiting as an explicit, separate decision — it's a contributor-facing
  dev aid, unrelated to the audit product's own MCP surface.
- **Rate limiting / RBAC / audit logging / CSP headers** beyond Laravel
  and Fortify's defaults. → Phase 10/13.
- **CI beyond the starter kit's `tests.yml`** (lint/types/tests on push +
  PR, via `composer ci:check`). A LaraDogs-specific CI/CD story
  (e.g. running LaraDogs against itself, or against sample projects) is
  Phase 12 scope.

See [`phases.md`](phases.md) for the Phase 0 Definition of Done and what
Phase 1 should pick up first.
