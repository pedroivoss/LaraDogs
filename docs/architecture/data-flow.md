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
[ADR-0009](decisions/ADR-0009-audit-engine-foundation.md). As of Phase 4
one real analyzer is registered (`composer-audit`) and `Process/` has a
real implementation — see the next section.

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
[ADR-0004](decisions/ADR-0004-scanner-execution-strategy.md). Phases 1–4
(above) already deliver stack detection, orchestration, real process
execution, one real scanner (`composer audit`), and persistence/
lifecycle; what's missing is: more real analyzers (`npm audit`, PHPStan/
Larastan, ESLint, Semgrep, ...), correlating the same underlying issue
across multiple scanners into one `Finding`, Laravel-aware rules layered
on top of generic scanner output, and exposure beyond the plain
`laradogs:audit` CLI (MCP tool, Dashboard).

```
CLI / MCP tool / Dashboard "Run Scan" action
  │
  ▼
Audit Core: stack detection (Project Discovery — implemented)
  ▼
Audit Core: analyzer selection (Audit Engine planning — implemented;
  │  one real analyzer registered, composer-audit — Phase 4)
  ▼
Audit Core: analyzer execution
  │  (real analyzers, isolated subprocess via ProcessRunner — implemented
  │   for composer-audit, Phase 4; more analyzers TODO)
  ▼
Audit Core: normalization
  │  (raw scanner output → FindingCandidate — implemented, with
  │   composer-audit as the first real producer, Phase 4)
  ▼
Audit Core: correlation + deduplication
  │  (same underlying issue reported by MULTIPLE scanners → one Finding —
  │   not yet meaningful with only one real scanner; fingerprinting/
  │   ingestion for a single scanner's output is implemented, Phase 3)
  ▼
Audit Core: Laravel-aware rule pass
  │  (framework-specific heuristics layered on top of generic scanner
  │   output — not started)
  ▼
Persistence: Finding ingestion + lifecycle (implemented, Phase 3 — see above)
  ▼
Findings ──▶ CLI output (implemented, Phase 4) / MCP tool response /
             Dashboard views / CI gate (not started)
```

Every "Audit Core" box now corresponds to real code
(`app/Audit/Discovery/`, `app/Audit/Engine/`, `app/Audit/Analyzers/`,
`app/Audit/Findings/`) for at least one scanner end-to-end; what remains
is breadth (more scanners), correlation across them, and Laravel-aware
rules. See [`components.md`](components.md) for what exists today, and
[`../roadmap/roadmap.md`](../roadmap/roadmap.md) for phase status.
