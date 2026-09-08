# Data Flow

## Today: a standard Inertia request

This is the only data flow that exists in the codebase right now.

```
Browser
  │  GET /dashboard
  ▼
routes/web.php ──▶ Controller ──▶ Inertia::render('dashboard')
  │
  ▼
HandleInertiaRequests middleware (shares auth user, flash, appearance)
  │
  ▼
React page component (resources/js/pages/dashboard.tsx)
  │  renders using props sent from the controller
  ▼
Browser (client-side navigation from then on via Inertia)
```

Wayfinder-generated helpers (`resources/js/actions`, `resources/js/routes`)
let the React side call `login.store()`-style functions instead of
hand-written URL strings; they're regenerated from `routes/*.php` at build
time, not hand-maintained.

## Implemented: Project Discovery (Phase 1)

```
CLI (`php artisan laradogs:inspect {path}`)
  │
  ▼
App\Console\Commands\InspectProjectCommand (thin adapter, no logic)
  │
  ▼
App\Audit\Discovery\ProjectDiscovery::discover()
  │  path validation (exists / is a directory / readable) → ProjectFilesystem
  ▼
ComposerManifest / NpmManifest (safe JSON parse of composer.json/lock,
  │                              package.json — never executed)
  ▼
Inspectors (Composer, Laravel, Frontend, Testing, Infrastructure, Database)
  │  each reads only static evidence via ProjectFilesystem
  ▼
ProfileBuilder → ProjectProfile
  │
  ▼
DiscoveryResult ──▶ CLI human-readable output / `--json`
```

No `Finding` is produced and no scanner runs — see
[`../auditing/project-discovery.md`](../auditing/project-discovery.md) and
[ADR-0008](decisions/ADR-0008-static-project-discovery.md).

## Implemented: Audit Engine foundation (Phase 2)

```
AuditContext (runId, projectPath, ProjectProfile from Discovery, settings)
  │
  ▼
AuditEngine::plan()
  │  for each registered Analyzer: applicability(profile) → (if applicable)
  │  availability(context) — run() is never called here
  ▼
AuditPlan (ordered AuditPlanItem list — Planned / NotApplicable / Unavailable)
  │
  ▼
AuditEngine::execute(plan, context)
  │  Planned items: run() inside try/catch, exceptions normalized to Failed;
  │  NotApplicable/Unavailable items: carried over, run() never called;
  │  continue_on_failure=false: remaining Planned items after a failure → Skipped
  ▼
AuditRunResult (runId, plan, one AnalyzerExecution per item, timing)
```

No `Finding` is produced and nothing is persisted by the engine itself —
see [`../auditing/audit-engine.md`](../auditing/audit-engine.md) and
[ADR-0009](decisions/ADR-0009-audit-engine-foundation.md). As of Phase 5
three real analyzers are registered (`composer-audit`, `npm-audit`,
`semgrep`) and `Process/` has one real implementation all three reuse
unchanged — see the next three sections.

## Implemented: Composer Audit + real process execution (Phase 4)

```
CLI (`php artisan laradogs:audit {path}`) — prints only, never persists
  │                     OR
  │  App\Audit\Findings\Ingestion\ScanRunner::run() — persisted path
  ▼
ProjectDiscovery::discover() (Phase 1, unchanged)
  ▼
AuditEngine::run(context)  [ registry: ComposerAuditAnalyzer ]
  │  applicability(profile): composer.json + composer.lock both present
  │  availability(context): binary resolved (LaraDogs' own config/PATH
  │    only) + `composer --version` >= 2.4, via ProcessRunner
  ▼
ComposerAuditAnalyzer::run(context)
  │  SymfonyProcessRunner::run(ProcessCommand(
  │    argv: [composer, audit, --locked, --format=json, --no-interaction,
  │           --no-plugins, --no-scripts, --no-ansi],
  │    cwd: project path, env: explicit allowlist, timeout: configured))
  │  → timedOut / processStartFailed / outputTruncated / malformed JSON
  │    / unreachable-repositories all fail closed (never a false-clean
  │    Passed); a non-zero exit code alone is NOT a failure
  ▼
ComposerAuditParser::parse(stdout) → ComposerAuditReport
  │  (dedicated parser — never mixed with ProcessRunner; tolerant of
  │   missing/unexpected fields)
  ▼
AnalyzerResult (Passed, rawMetadata: advisories/abandoned/composer_version,
  │             coverage: always Unknown — see composer-audit.md)
  ▼
ComposerAuditAnalyzer::candidates(context, result) → list<FindingCandidate>
  │  (only when Status=Passed; ProducesFindingCandidates, in
  │   Findings\Ingestion, not Engine — no Engine→Findings dependency)
  ▼
[ persisted path only ] ScanRunner looks the analyzer back up in the same
  AnalyzerRegistry, then hands candidates to the existing ScanRecorder
  (Phase 3, unchanged) → Finding / FindingOccurrence persisted
```

See [`../auditing/analyzers/composer-audit.md`](../auditing/analyzers/composer-audit.md),
[`../development/process-execution.md`](../development/process-execution.md),
and [ADR-0011](decisions/ADR-0011-safe-external-process-execution.md).

## Implemented: Npm Audit (Phase 4.2)

```
CLI / ScanRunner (same two entry points as Composer Audit above)
  ▼
ProjectDiscovery::discover() [ FrontendProfile gained npmLockfile: Detection ]
  ▼
AuditEngine::run(context)  [ registry: ComposerAuditAnalyzer, NpmAuditAnalyzer ]
  │  applicability(profile): package.json + (package-lock.json OR
  │    npm-shrinkwrap.json) both present — yarn.lock/pnpm-lock.yaml alone
  │    does not count
  │  availability(context): binary resolved (LaraDogs' own config/PATH
  │    only) + `npm --version` >= 7.0.0, via the SAME ProcessRunner
  ▼
NpmAuditAnalyzer::run(context)
  │  SymfonyProcessRunner::run(ProcessCommand(
  │    argv: [npm, audit, --json, --package-lock-only, --ignore-scripts,
  │           --registry=<pinned, e.g. https://registry.npmjs.org>],
  │    cwd: project path,
  │    env: explicit allowlist + ALWAYS-forced NPM_CONFIG_USERCONFIG/
  │         NPM_CONFIG_CACHE (LaraDogs-controlled paths — never the
  │         target's `.npmrc`-influenced defaults, never a developer's
  │         personal $HOME/.npmrc credentials),
  │    timeout: configured))
  │  → timedOut / processStartFailed / outputTruncated / malformed or
  │    unrecognized-schema JSON (including registry/network-error and
  │    missing-lockfile-error shapes) all fail closed; a non-zero exit
  │    code alone is NOT a failure — npm's own default threshold makes
  │    ANY found vulnerability exit non-zero, the SAME code a registry
  │    failure also produces, so only JSON shape decides trust
  ▼
NpmAuditParser::parse(stdout) → NpmAuditReport
  │  (dedicated parser; extracts only real advisory OBJECTS from each
  │   package's `via` array, skipping plain-string cross-references to
  │   other packages' own entries — never fabricates a finding for a
  │   purely meta-vulnerable package)
  ▼
AnalyzerResult (Passed, rawMetadata: advisories/severity_counts/npm_version,
  │             coverage: always Unknown — see npm-audit.md)
  ▼
NpmAuditAnalyzer::candidates(context, result) → list<FindingCandidate>
  ▼
[ persisted path only ] ScanRunner (same, unchanged instance already
  looking up composer-audit above) also looks up npm-audit and hands its
  candidates to the same ScanRecorder → Finding / FindingOccurrence
  persisted, independently identified, no id collision with Composer's
```

The central Phase 4.2 research finding: a target's own `.npmrc` (read
automatically, no flag to disable it) could redirect `npm audit`'s
registry query to a server it controls — mitigated by always pinning
`--registry=` as an explicit CLI flag (npm's own documented config
precedence: CLI flags > env vars > npmrc files), verified to make the
project's own `.npmrc` registry override ineffective. See
[`../auditing/analyzers/npm-audit.md`](../auditing/analyzers/npm-audit.md#9-npm-configuration-security)
for the full investigation, including a documented residual gap
(scoped `@scope:registry=` overrides).

## Implemented: Semgrep (Phase 5)

```
CLI / ScanRunner (same two entry points as Composer/npm Audit above)
  ▼
ProjectDiscovery::discover() (unchanged — applicability reads php.isDetected())
  ▼
AuditEngine::run(context)  [ registry: ComposerAuditAnalyzer, NpmAuditAnalyzer, SemgrepAnalyzer ]
  │  applicability(profile): PHP detected (bundled ruleset is PHP-only)
  │  availability(context): binary resolved (LaraDogs' own config/PATH
  │    only) + `semgrep --version` >= 1.176.0, via the SAME ProcessRunner
  ▼
SemgrepTargetCollector::collect(projectPath) → list<absolute .php path>
  │  bounded, symlink-rejecting, realpath-contained walk; excludes
  │  vendor/, node_modules/, storage/, bootstrap/cache/, public/build/,
  │  dist/, coverage/, .git/ — a LaraDogs-controlled list, never derived
  │  from the target's own .gitignore
  ▼
SemgrepAnalyzer::run(context)
  │  SymfonyProcessRunner::run(ProcessCommand(
  │    argv: [semgrep, scan, --config, <bare rules filename>, --json,
  │           --verbose, --metrics=off, --no-git-ignore, --oss-only,
  │           --timeout=<N>, --max-target-bytes=<N>, <file1>, <file2>, ...],
  │    cwd: the BUNDLED RULES' OWN DIRECTORY (not the project path — this
  │         is what makes Semgrep's own check_id come back clean/unprefixed),
  │    env: explicit allowlist + ALWAYS-forced SEMGREP_SETTINGS_FILE +
  │         SEMGREP_SEND_METRICS=off (SEMGREP_APP_TOKEN never allowlisted),
  │    timeout: configured))
  │  → timedOut / processStartFailed / outputTruncated / non-zero exit
  │    (a genuine config/rule-load failure for Semgrep, unlike Composer/
  │    npm) / malformed JSON all fail closed
  ▼
SemgrepParser::parse(stdout, knownRuleIds) → SemgrepScanReport
  │  (dedicated parser; matches each check_id against SemgrepRuleCatalog's
  │   known ids by exact-or-suffix match — never trusts check_id verbatim)
  ▼
SemgrepCoverageEvaluator::isFullyCovered(report)
  │  Explicit(catalog rule ids) only when zero errors[] AND no non-benign
  │  paths.skipped[] reason — Unknown the moment there's any doubt
  ▼
AnalyzerResult (Passed, rawMetadata: findings/errors/skipped/semgrep_version,
  │             coverage: Explicit or Unknown — see semgrep.md#coverage)
  ▼
SemgrepAnalyzer::candidates(context, result) → list<FindingCandidate>
  │  path normalized to project-relative; codeSnippet read directly from
  │  the source file (Semgrep's own `extra.lines` requires a login and is
  │  never used) and redacted via the existing EvidenceRedactor
  ▼
[ persisted path only ] ScanRunner (same, unchanged instance already
  looking up composer-audit/npm-audit above) also looks up semgrep and
  hands its candidates to the same ScanRecorder → Finding /
  FindingOccurrence persisted, independently identified
```

The central Phase 5 research finding: pointing Semgrep at a DIRECTORY lets
the target's own `.semgrepignore` hide a file from analysis entirely
(reproduced directly) — mitigated by never doing that: `SemgrepTargetCollector`
performs its own file walk and every target is passed as an explicit argv
path instead, verified to bypass `.semgrepignore`/`.gitignore` regardless
of what either file says. See
[`../auditing/analyzers/semgrep.md`](../auditing/analyzers/semgrep.md) and
[ADR-0012](decisions/ADR-0012-trusted-static-analysis-rules.md) for the
full investigation and trust-boundary decision.

## Implemented: Finding ingestion and lifecycle (Phase 3)

```
ScanRecorder::startScan(project, profile)
  │  creates a Scan row (status=running) with a project_profile snapshot
  ▼
[ real AuditEngine::run() from Phase 2, synthetic FindingCandidates per
  analyzer — no real analyzer produces these yet ]
  ▼
ScanRecorder::completeScan(scan, runResult, candidatesByAnalyzer)
  │
  ├─▶ one ScanAnalyzerExecution persisted per AnalyzerExecution
  │
  ├─▶ FindingIngestor::ingest() per candidate
  │     fingerprint → find-or-create Finding (locked, project-scoped)
  │     → create/update FindingOccurrence for this scan
  │     → reopen if previously Resolved; suppressed statuses untouched
  │
  ├─▶ FindingReconciler::reconcile()
  │     auto-resolves OPEN/CONFIRMED findings only when: analyzer
  │     completed Passed this scan AND its declared AnalyzerCoverage
  │     verifies the finding's rule_id AND it was not re-observed
  │     (Passed alone is not sufficient — Phase 3.1)
  │
  ▼
Scan marked Completed (or Failed, on exception) with findings_summary
```

See [`../auditing/findings-lifecycle.md`](../auditing/findings-lifecycle.md)
and [ADR-0010](decisions/ADR-0010-finding-identity-occurrences-and-lifecycle.md).
Phase 4 exercises this same flow with the first real candidates — see
above.

## Planned: correlation, Laravel-aware rules, and additional scanners

Not implemented. Recorded here so the eventual implementation has a
target shape consistent with
[ADR-0002](decisions/ADR-0002-application-architecture.md) and
[ADR-0004](decisions/ADR-0004-scanner-execution-strategy.md). Phases 1–5
(above) already deliver stack detection, orchestration, real process
execution, three real scanners (`composer audit`, `npm audit`, a small
bundled Semgrep ruleset), and persistence/lifecycle; what's missing is:
more real analyzers/rules (PHPStan/Larastan, ESLint, OSV-Scanner, Trivy,
and — most importantly — a comprehensive Laravel-aware Semgrep rule
library beyond this phase's 3 proof-of-vertical rules), correlating the
same underlying issue across multiple scanners into one `Finding`, and
exposure beyond the plain `laradogs:audit` CLI (MCP tool, Dashboard).

```
CLI / MCP tool / Dashboard "Run Scan" action
  │
  ▼
Audit Core: stack detection (Project Discovery — implemented)
  ▼
Audit Core: analyzer selection (Audit Engine planning — implemented;
  │  three real analyzers registered, composer-audit + npm-audit + semgrep,
  │  coexisting deterministically — Phase 4 / Phase 4.2 / Phase 5)
  ▼
Audit Core: analyzer execution
  │  (real analyzers, isolated subprocess via the SAME ProcessRunner —
  │   implemented for composer-audit, npm-audit, and semgrep; more
  │   analyzers TODO)
  ▼
Audit Core: normalization
  │  (raw scanner output → FindingCandidate — implemented, with
  │   composer-audit (Phase 4), npm-audit (Phase 4.2), and semgrep
  │   (Phase 5) as real producers)
  ▼
Audit Core: correlation + deduplication
  │  (same underlying issue reported by MULTIPLE scanners → one Finding —
  │   not yet meaningful across only two, non-overlapping-ecosystem
  │   scanners; fingerprinting/ingestion for a single scanner's output is
  │   implemented, Phase 3)
  ▼
Audit Core: Laravel-aware rule pass
  │  (framework-specific heuristics layered on top of generic scanner
  │   output — a first, deliberately minimal foundation exists as of
  │   Phase 5, 3 bundled Semgrep rules proving the vertical; the
  │   comprehensive rule library itself is not started)
  ▼
Persistence: Finding ingestion + lifecycle (implemented, Phase 3 — see above)
  ▼
Findings ──▶ CLI output (implemented, Phase 4) / MCP tool response /
             Dashboard views / CI gate (not started)
```

Every "Audit Core" box now corresponds to real code
(`app/Audit/Discovery/`, `app/Audit/Engine/`, `app/Audit/Analyzers/`,
`app/Audit/Findings/`) for at least one scanner end-to-end; what remains
is breadth (more scanners/rules), correlation across them, and a
comprehensive Laravel-aware rule library. See
[`components.md`](components.md) for what exists today, and
[`../roadmap/roadmap.md`](../roadmap/roadmap.md) for phase status.
