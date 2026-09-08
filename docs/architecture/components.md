# Components (Current State)

This describes what exists in the repository today — the Laravel starter
kit foundation from Phase 0, Project Discovery (Phase 1), the Audit
Engine foundation (Phase 2), the Finding domain/lifecycle (Phase 3), the
first real analyzer/process-execution implementation (Phase 4), a second
real analyzer (Phase 4.2), and the first static analyzer / SAST
foundation (Phase 5) — not the full target audit architecture. See
[`overview.md`](overview.md) for that.

## Backend (`app/`)

| Path                             | Purpose                                                                                                                                                                                                                           |
| -------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `app/Actions/Fortify/`           | Fortify action classes (user creation, password validation/reset) — starter-kit auth, not LaraDogs-specific.                                                                                                                      |
| `app/Audit/Discovery/`           | **Project Discovery Core** (Phase 1) — see below.                                                                                                                                                                                 |
| `app/Audit/Engine/`              | **Audit Engine foundation** (Phase 2), now with a real `ProcessRunner` (Phase 4) — see below.                                                                                                                                     |
| `app/Audit/Findings/`            | **Finding domain services** (Phase 3), now with `ScanRunner`/`ProducesFindingCandidates` (Phase 4) — see below.                                                                                                                   |
| `app/Audit/Analyzers/`           | **Real analyzer implementations** (Phase 4: Composer; Phase 4.2: npm; Phase 5: Semgrep) — the outer namespace depending on both Engine and Findings — see below.                                                                  |
| `app/Http/Controllers/`          | Inertia page controllers and Fortify-adjacent controllers.                                                                                                                                                                        |
| `app/Http/Controllers/Settings/` | User settings pages (profile, password, appearance, two-factor, passkeys).                                                                                                                                                        |
| `app/Http/Middleware/`           | `HandleAppearance` (theme cookie) and `HandleInertiaRequests` (shared Inertia props).                                                                                                                                             |
| `app/Http/Requests/`             | Form request validation classes.                                                                                                                                                                                                  |
| `app/Models/`                    | `User` (starter-kit); `Audit/` — persistence models (Phase 3) — see below.                                                                                                                                                        |
| `app/Providers/`                 | `AppServiceProvider` — Fortify bindings, plus `ProcessRunner` → `SymfonyProcessRunner` (Phase 4) and a singleton `AnalyzerRegistry` with `composer-audit` (Phase 4), `npm-audit` (Phase 4.2), and `semgrep` (Phase 5) registered. |
| `app/Console/Commands/`          | `InspectProjectCommand` (`laradogs:inspect`, Phase 1); `AuditCommand` (`laradogs:audit`, Phase 4) — thin CLI adapters, no detection/analyzer logic of their own.                                                                  |

### Project Discovery (`app/Audit/Discovery/`)

The first real Audit Core code (see
[ADR-0002](decisions/ADR-0002-application-architecture.md)): a static,
evidence-based stack detector with no dependency on the web UI, MCP, or a
database. Full detail, security model, and evidence sources:
[`../auditing/project-discovery.md`](../auditing/project-discovery.md);
the decision behind _how_ it's safe to run against untrusted code:
[ADR-0008](decisions/ADR-0008-static-project-discovery.md).

| Path                               | Purpose                                                                                                                                                                                                                                                                                                               |
| ---------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `ProjectDiscovery.php`             | Entry point — orchestrates the inspectors below into a `DiscoveryResult`.                                                                                                                                                                                                                                             |
| `Filesystem/ProjectFilesystem.php` | Bounded, rooted, read-only file access — the only way this code touches the target's filesystem.                                                                                                                                                                                                                      |
| `Manifests/`                       | `ComposerManifest`/`NpmManifest` — safe JSON parsing of `composer.json`/`composer.lock`/`package.json`.                                                                                                                                                                                                               |
| `Inspectors/`                      | `ComposerInspector`, `LaravelInspector`, `FrontendInspector`, `TestingInspector`, `InfrastructureInspector`, `DatabaseInspector` — one concern each.                                                                                                                                                                  |
| `Profile/`                         | `ProjectProfile` and its sections (`BackendProfile`, `FrontendProfile` — gained `npmLockfile: Detection` in Phase 4.2, needed by `NpmAuditAnalyzer`'s applicability — `TestingProfile`, `InfrastructureProfile`, `DatabaseProfile`), `ProfileBuilder`, and the `ProjectType`/`DatabaseDriver`/`PackageManager` enums. |
| `Support/`                         | `Detection`/`VersionDetection` value objects, `DetectionStatus` enum, `DiscoveryIssue`.                                                                                                                                                                                                                               |

Discovery itself has no `Finding`/`Scan` model or persistence — that's a
separate concern, implemented under `app/Audit/Findings/`/
`app/Models/Audit/` (Phase 3) — see below.

### Audit Engine (`app/Audit/Engine/`)

Orchestration between a `ProjectProfile` and real scanner integrations.
As of Phase 4, `Process/` has a real implementation
(`SymfonyProcessRunner`), reused unchanged by every analyzer since. Three
real analyzers are registered in production, coexisting deterministically
in one registry: `App\Audit\Analyzers\Composer\ComposerAuditAnalyzer`
(Phase 4), `App\Audit\Analyzers\Npm\NpmAuditAnalyzer` (Phase 4.2), and
`App\Audit\Analyzers\Semgrep\SemgrepAnalyzer` (Phase 5 — the first to use
`AnalyzerCoverage::Explicit`). Full
detail, lifecycle, and security boundary:
[`../auditing/audit-engine.md`](../auditing/audit-engine.md); the decision
behind the Analyzer contract: [ADR-0009](decisions/ADR-0009-audit-engine-foundation.md);
the decision behind process execution:
[ADR-0011](decisions/ADR-0011-safe-external-process-execution.md).

| Path               | Purpose                                                                                                                                                                                                                                     |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `AuditEngine.php`  | Orchestrates registry → applicability/availability → plan → execution → result.                                                                                                                                                             |
| `AuditContext.php` | What an analyzer needs: run id, project path, `ProjectProfile`, execution settings.                                                                                                                                                         |
| `Contracts/`       | `Analyzer` interface, `AnalyzerId`, `AnalyzerCategory`, `Applicability`/`ApplicabilityStatus`, `Availability`/`AvailabilityStatus`.                                                                                                         |
| `Registry/`        | `AnalyzerRegistry` (explicit registration, duplicate-id guard, deterministic order), `DuplicateAnalyzerIdException`.                                                                                                                        |
| `Plan/`            | `AuditPlan`, `AuditPlanItem` — inspectable, JSON-safe, built without executing anything.                                                                                                                                                    |
| `Execution/`       | `ExecutionStatus`, `AnalyzerResult`, `AnalyzerDiagnostic`/`DiagnosticLevel`, `AnalyzerCoverage`/`CoverageMode` (Phase 3.1 — what an analyzer declares it actually verified, separate from `status`), `AnalyzerExecution`, `AuditRunResult`. |
| `Process/`         | `ProcessRunner` (interface), `ProcessCommand`, `ProcessResult`, `SymfonyProcessRunner` (Phase 4 — the real, argv-only, env-allowlisted, output-capped implementation).                                                                      |

`ComposerAuditAnalyzer` (`app/Audit/Analyzers/Composer/`),
`NpmAuditAnalyzer` (`app/Audit/Analyzers/Npm/`), and `SemgrepAnalyzer`
(`app/Audit/Analyzers/Semgrep/`) are the three real
`Analyzer`s registered in production (see
`App\Providers\AppServiceProvider`); synthetic ones for exercising the
engine itself still live under `tests/Support/Engine/Analyzers/`.

### Findings (`app/Audit/Findings/`, `app/Models/Audit/`)

The persistent domain: a `Finding`'s stable identity, its per-scan
`FindingOccurrence` evidence, and the lifecycle service that safely
auto-resolves/reopens findings. Full detail, security boundary, and
auto-resolution safety:
[`../auditing/findings-lifecycle.md`](../auditing/findings-lifecycle.md);
the decision behind identity/fingerprinting/lifecycle:
[ADR-0010](decisions/ADR-0010-finding-identity-occurrences-and-lifecycle.md).

| Path                                                                                              | Purpose                                                                                                                                                                        |
| ------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `app/Models/Audit/Project.php`                                                                    | Something auditable registered with LaraDogs.                                                                                                                                  |
| `app/Models/Audit/Scan.php`                                                                       | One immutable audit run — `ProjectProfile` snapshot, status, timing.                                                                                                           |
| `app/Models/Audit/ScanAnalyzerExecution.php`                                                      | Persisted `AnalyzerExecution` (Phase 2) per scan, including declared `coverage` — what auto-resolution safety depends on.                                                      |
| `app/Models/Audit/Casts/AsAnalyzerCoverage.php`                                                   | Eloquent cast: `AnalyzerCoverage` (Phase 2) &lt;-&gt; JSON, defaulting to `Unknown` on anything unparseable.                                                                   |
| `app/Models/Audit/Finding.php`                                                                    | The stable, cross-scan logical identity of an issue.                                                                                                                           |
| `app/Models/Audit/FindingOccurrence.php`                                                          | Evidence observed for a Finding in one specific scan.                                                                                                                          |
| `app/Models/Audit/FindingStatusHistory.php`                                                       | Append-only lifecycle transition audit trail.                                                                                                                                  |
| `Findings/FindingCandidate.php`                                                                   | The scanner-agnostic "an analyzer observed this" DTO — the seam a real Phase 4+ analyzer targets.                                                                              |
| `Findings/Severity.php`, `Confidence.php`, `FindingStatus.php`, `ScanStatus.php`, `ActorType.php` | Domain enums.                                                                                                                                                                  |
| `Findings/Fingerprint/Fingerprinter.php`                                                          | Versioned (`v1`), line-number-independent identity computation.                                                                                                                |
| `Findings/Redaction/EvidenceRedactor.php`                                                         | Defense-in-depth secret masking for persisted evidence.                                                                                                                        |
| `Findings/Lifecycle/FindingLifecycleService.php`                                                  | The only code path allowed to change a Finding's status.                                                                                                                       |
| `Findings/Ingestion/FindingIngestor.php`, `FindingReconciler.php`, `ScanRecorder.php`             | Find-or-create + occurrence recording, safe auto-resolution sweep, and the Phase 1+2+3 tie-together.                                                                           |
| `Findings/Ingestion/ProducesFindingCandidates.php`                                                | Phase 4 — implemented by a concrete outer-layer analyzer to normalize its own `AnalyzerResult` into `FindingCandidate`s, without `Analyzer`/Engine ever depending on Findings. |
| `Findings/Ingestion/ScanRunner.php`                                                               | Phase 4 — the orchestration seam: runs a real `AuditEngine`, then normalizes every `ProducesFindingCandidates` analyzer's result and hands it to `ScanRecorder`.               |

`ComposerAuditAnalyzer` (Phase 4), `NpmAuditAnalyzer` (Phase 4.2), and
`SemgrepAnalyzer` (Phase 5) are the real producers of a
`FindingCandidate`. `config/laradogs.php` holds a placeholder `version`
string recorded on every scan (no release/tagging scheme exists yet),
plus `process.*`/`composer.*` (Phase 4), `npm.*` (Phase 4.2), and
`semgrep.*` (Phase 5) settings — see
[`analyzers/composer-audit.md`](../auditing/analyzers/composer-audit.md),
[`analyzers/npm-audit.md`](../auditing/analyzers/npm-audit.md), and
[`analyzers/semgrep.md`](../auditing/analyzers/semgrep.md).

### Analyzers (`app/Audit/Analyzers/`)

The outer namespace for concrete, real analyzers — the only place in the
codebase allowed to depend on BOTH `App\Audit\Engine` and
`App\Audit\Findings` at once (see
[ADR-0011](decisions/ADR-0011-safe-external-process-execution.md),
[ADR-0012](decisions/ADR-0012-trusted-static-analysis-rules.md),
[`../auditing/analyzers/composer-audit.md`](../auditing/analyzers/composer-audit.md),
[`../auditing/analyzers/npm-audit.md`](../auditing/analyzers/npm-audit.md),
and [`../auditing/analyzers/semgrep.md`](../auditing/analyzers/semgrep.md)).
`Composer/`, `Npm/`, and `Semgrep/` deliberately do NOT share a base
class — a small amount of structural duplication between them was
accepted rather than force a premature shared abstraction (see
npm-audit.md's own rationale).

| Path                                                       | Purpose                                                                                                                                                                                         |
| ---------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Composer/ComposerAuditAnalyzer.php`                       | Implements both `Analyzer` and `ProducesFindingCandidates`; runs `composer audit --locked --no-plugins --no-scripts`.                                                                           |
| `Composer/ComposerBinaryResolver.php`                      | Resolves the `composer` executable from LaraDogs' own config/PATH only — never from the target.                                                                                                 |
| `Composer/ComposerAuditParser.php`                         | Dedicated, defensive JSON parser for `composer audit --format=json` — kept separate from `ProcessRunner` and the analyzer.                                                                      |
| `Composer/ComposerAdvisory.php`, `ComposerAuditReport.php` | Parsed-report value objects.                                                                                                                                                                    |
| `Npm/NpmAuditAnalyzer.php`                                 | Implements both `Analyzer` and `ProducesFindingCandidates`; runs `npm audit --package-lock-only --ignore-scripts --registry=<pinned>`.                                                          |
| `Npm/NpmBinaryResolver.php`                                | Resolves the `npm` executable from LaraDogs' own config/PATH only — never `./node_modules/.bin/npm` from the target.                                                                            |
| `Npm/NpmAuditParser.php`                                   | Dedicated, defensive JSON parser for `npm audit --json` — kept separate from `ProcessRunner` and the analyzer.                                                                                  |
| `Npm/NpmAdvisory.php`, `NpmAuditReport.php`                | Parsed-report value objects.                                                                                                                                                                    |
| `Semgrep/SemgrepAnalyzer.php`                              | Implements both `Analyzer` and `ProducesFindingCandidates`; runs `semgrep scan --config <bundled rules> --json --verbose --metrics=off` against an explicit, LaraDogs-collected file list.      |
| `Semgrep/SemgrepBinaryResolver.php`                        | Resolves the `semgrep` executable from LaraDogs' own config/PATH only — never from the target.                                                                                                  |
| `Semgrep/SemgrepTargetCollector.php`                       | Bounded, symlink-rejecting, realpath-contained file walk — builds the explicit target list that bypasses `.semgrepignore`/`.gitignore`.                                                         |
| `Semgrep/SemgrepParser.php`                                | Dedicated, defensive JSON parser for `semgrep scan --json` — matches `check_id` against the known rule catalog rather than trusting it verbatim.                                                |
| `Semgrep/SemgrepCoverageEvaluator.php`                     | Decides Explicit-vs-Unknown coverage from a scan's `errors`/`skipped` — see [`../auditing/analyzers/semgrep.md`](../auditing/analyzers/semgrep.md#coverage-the-first-analyzer-to-use-explicit). |
| `Semgrep/SemgrepRuleCatalog.php`, `SemgrepRule.php`        | The bundled ruleset manifest — rule ids, `AnalyzerCategory`/`Confidence` policy, ruleset version.                                                                                               |
| `Semgrep/SemgrepFinding.php`, `SemgrepScanReport.php`      | Parsed-report value objects.                                                                                                                                                                    |

## Frontend (`resources/js/`)

Inertia + React 19 + TypeScript, using shadcn/ui components under
`components/ui/`.

| Path                                | Purpose                                                                                                                                                                                                                                              |
| ----------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `pages/`                            | One Inertia page per route: `welcome.tsx`, `dashboard.tsx`, `auth/*`, `settings/*`.                                                                                                                                                                  |
| `layouts/`                          | `app-layout.tsx` (sidebar/header variants) and `auth-layout.tsx` (simple/card/split variants) — see the starter kit's own customization docs for switching variants.                                                                                 |
| `components/`                       | Reusable UI: sidebar/nav, two-factor and passkey management, shadcn primitives in `components/ui/`.                                                                                                                                                  |
| `hooks/`                            | `use-appearance`, `use-two-factor-auth`, `use-clipboard`, etc.                                                                                                                                                                                       |
| `actions/`, `routes/`, `wayfinder/` | **Generated at build time** by Laravel Wayfinder (`php artisan wayfinder:generate`), gitignored. Provide type-safe route/action helpers callable from TypeScript — regenerate with `npm run build` or `npm run dev` after adding routes/controllers. |
| `types/`                            | Shared TypeScript types (`auth.ts`, `navigation.ts`, `ui.ts`).                                                                                                                                                                                       |

There is **no `Dashboard/`, `Findings/`, `Scans/` page group yet** — that's
Phase 7.

## Routing (`routes/`)

- `web.php` — home (`/`) and dashboard (`/dashboard`, `auth`+`verified`
  middleware).
- `settings.php` — profile/password/appearance/two-factor/passkey settings.
- `console.php` — Artisan console routes (scheduled commands would go
  here; none defined yet).
- The `/up` health-check route is registered in `bootstrap/app.php` via
  Laravel's built-in `health:` routing option, not a custom controller.

## Database

- Driver today: SQLite (`database/database.sqlite`, gitignored via
  `database/.gitignore`), selected via `DB_CONNECTION=sqlite` in
  `.env.example` — the Quick Start default, not an architectural
  requirement. MySQL, MariaDB, and PostgreSQL are equally supported
  through the same `config/database.php` (stock Laravel connections, no
  LaraDogs-specific code); see
  [ADR-0007](decisions/ADR-0007-database-agnostic-persistence.md).
  The current Docker quick-start image only bundles the `pdo_sqlite`
  extension — see [`../development/docker.md`](../development/docker.md).
- Migrations: starter-kit ones (users, cache, jobs, passkeys, two-factor
  columns) plus the Phase 3 audit-domain schema — `projects`, `scans`,
  `scan_analyzer_executions`, `findings`, `finding_occurrences`,
  `finding_status_histories` — plus a Phase 3.1 follow-up migration adding
  a nullable `coverage` column to `scan_analyzer_executions` (the existing
  table's migration was not edited, preserving schema history). All
  portable Laravel primitives (no vendor-specific enum types/JSON
  operators/generated columns) — see
  [`../auditing/findings-lifecycle.md`](../auditing/findings-lifecycle.md#database-portability).

## Testing

- Pest, under `tests/Feature` and `tests/Unit`. Starter-kit auth flows,
  Project Discovery (Phase 1), the Audit Engine (Phase 2/3.1), and the
  Finding domain (Phase 3/3.1) together account for 161 tests; Phase 4
  adds real `ProcessRunner` tests, `ComposerAuditParser`/
  `ComposerAuditAnalyzer` tests, and a full end-to-end pipeline test;
  Phase 4.1 adds Docker-adjacent env-allowlist/read-only-target tests;
  Phase 4.2 adds Discovery's new `npmLockfile` detection tests,
  `NpmAuditParser`/`NpmAuditAnalyzer` tests, an npm end-to-end pipeline
  test, and a Composer+npm multi-analyzer coexistence test; Phase 5 adds
  `SemgrepParser`/`SemgrepCoverageEvaluator`/`SemgrepTargetCollector`/
  `SemgrepRuleCatalog` unit tests, `SemgrepAnalyzer` feature tests, a
  Semgrep end-to-end pipeline test, the 5-case Finding lifecycle proof
  against the real analyzer, and 4 opt-in real-`semgrep`-binary tests —
  329 tests total (318 passing + 11 opt-in, network/real-binary-dependent
  tests skipped by default), all passing. See
  [`../development/testing.md`](../development/testing.md) and
  [`../development/process-execution.md`](../development/process-execution.md).
- `tests/Support/Engine/Analyzers/` — synthetic `Analyzer` implementations
  (never autoloaded in production) used only by the Audit Engine's tests.
- `tests/Support/Findings/SyntheticCandidates.php` — synthetic
  `FindingCandidate`s (SQL injection, N+1, vulnerable dependency, config
  issue) used by the Finding domain's own tests.
- `tests/Support/Process/FakeProcessRunner.php` (Phase 4) — a scripted
  `ProcessRunner` test double; never spawns a real process. Reused
  unchanged by `composer-audit`, `npm-audit`, and `semgrep` tests.
- `tests/Fixtures/discovery/` — small, synthetic project fixtures (never
  real projects) used by Discovery's, the Composer analyzer's (Phase 4),
  and the npm analyzer's (Phase 4.2 — includes dedicated
  npm-shrinkwrap-only, pnpm-only, package-json-without-lock,
  npm-malicious-scripts, and npm-malicious-npmrc fixtures) tests.
- `tests/Fixtures/process/` (Phase 4) — small PHP scripts (echo-args,
  sleep, exit-code, stdout/stderr, huge-output), run via `PHP_BINARY`,
  used only to test the real `ProcessRunner` — never anything from a
  target project.
- `tests/Fixtures/composer-audit/` (Phase 4) — synthetic
  `composer audit --format=json` JSON fixtures (clean, with advisories,
  with abandoned packages, unreachable repositories, malformed, truncated).
- `tests/Fixtures/npm-audit/` (Phase 4.2) — synthetic (and, for the
  transitive-vulnerability case, a real captured shape) `npm audit --json`
  fixtures (clean, direct vulnerability, transitive/meta-vulnerability,
  malformed, registry-error, missing-lockfile-error, unrecognized/older
  schema, truncated).
- `tests/Fixtures/semgrep/` (Phase 5) — `captured-json/` (synthetic
  `semgrep scan --json` fixtures: clean, with-findings, unrecognized rule
  id, partial-parsing, benign/dangerous skip, invalid-rule-config-error)
  and small real PHP fixture projects (`php-project` with a `vendor/`
  exclusion proof, `clean-project`, `ignore-bypass-project` with a real
  `.semgrepignore`/`.gitignore`, `malicious-execution-project` with a
  `system()` call that must never actually run).

## Tooling already wired by the starter kit

- **Pint** (PHP formatting) — `composer lint` / `composer lint:check`.
- **Larastan/PHPStan** (static analysis) — `composer types:check`.
- **ESLint + Prettier (via `vp check`)** — `npm run check`.
- **Composer `test` script** chains config-clear → lint:check → types:check
  → `php artisan test`, giving one command for the full local gate.

None of this is LaraDogs-specific tooling — it's the standard starter-kit
developer experience, reused as-is rather than replaced (see
[`../development/conventions.md`](../development/conventions.md)).
