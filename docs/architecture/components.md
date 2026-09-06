# Components (Current State)

This describes what exists in the repository today — the Laravel starter
kit foundation from Phase 0, Project Discovery (Phase 1), the Audit
Engine foundation (Phase 2), and the Finding domain/lifecycle (Phase 3) —
not the full target audit architecture. See [`overview.md`](overview.md)
for that.

## Backend (`app/`)

| Path                             | Purpose                                                                                                                |
| -------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| `app/Actions/Fortify/`           | Fortify action classes (user creation, password validation/reset) — starter-kit auth, not LaraDogs-specific.           |
| `app/Audit/Discovery/`           | **Project Discovery Core** (Phase 1) — see below.                                                                      |
| `app/Audit/Engine/`              | **Audit Engine foundation** (Phase 2) — see below.                                                                     |
| `app/Audit/Findings/`            | **Finding domain services** (Phase 3) — see below.                                                                     |
| `app/Http/Controllers/`          | Inertia page controllers and Fortify-adjacent controllers.                                                             |
| `app/Http/Controllers/Settings/` | User settings pages (profile, password, appearance, two-factor, passkeys).                                             |
| `app/Http/Middleware/`           | `HandleAppearance` (theme cookie) and `HandleInertiaRequests` (shared Inertia props).                                  |
| `app/Http/Requests/`             | Form request validation classes.                                                                                       |
| `app/Models/`                    | `User` (starter-kit); `Audit/` — persistence models (Phase 3) — see below.                                             |
| `app/Providers/`                 | `AppServiceProvider`, Fortify service provider bindings.                                                               |
| `app/Console/Commands/`          | `InspectProjectCommand` (`laradogs:inspect`) — thin CLI adapter over Project Discovery, no detection logic of its own. |

### Project Discovery (`app/Audit/Discovery/`)

The first real Audit Core code (see
[ADR-0002](decisions/ADR-0002-application-architecture.md)): a static,
evidence-based stack detector with no dependency on the web UI, MCP, or a
database. Full detail, security model, and evidence sources:
[`../auditing/project-discovery.md`](../auditing/project-discovery.md);
the decision behind _how_ it's safe to run against untrusted code:
[ADR-0008](decisions/ADR-0008-static-project-discovery.md).

| Path                               | Purpose                                                                                                                                                                                                                 |
| ---------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `ProjectDiscovery.php`             | Entry point — orchestrates the inspectors below into a `DiscoveryResult`.                                                                                                                                               |
| `Filesystem/ProjectFilesystem.php` | Bounded, rooted, read-only file access — the only way this code touches the target's filesystem.                                                                                                                        |
| `Manifests/`                       | `ComposerManifest`/`NpmManifest` — safe JSON parsing of `composer.json`/`composer.lock`/`package.json`.                                                                                                                 |
| `Inspectors/`                      | `ComposerInspector`, `LaravelInspector`, `FrontendInspector`, `TestingInspector`, `InfrastructureInspector`, `DatabaseInspector` — one concern each.                                                                    |
| `Profile/`                         | `ProjectProfile` and its sections (`BackendProfile`, `FrontendProfile`, `TestingProfile`, `InfrastructureProfile`, `DatabaseProfile`), `ProfileBuilder`, and the `ProjectType`/`DatabaseDriver`/`PackageManager` enums. |
| `Support/`                         | `Detection`/`VersionDetection` value objects, `DetectionStatus` enum, `DiscoveryIssue`.                                                                                                                                 |

Discovery itself has no `Finding`/`Scan` model or persistence — that's a
separate concern, implemented under `app/Audit/Findings/`/
`app/Models/Audit/` (Phase 3) — see below.

### Audit Engine (`app/Audit/Engine/`)

Orchestration foundation between a `ProjectProfile` and real scanner
integrations (Phase 4+) — no real analyzer exists yet, only the contract
and synthetic ones for testing. Full detail, lifecycle, and security
boundary: [`../auditing/audit-engine.md`](../auditing/audit-engine.md);
the decision behind the Analyzer contract and process-execution boundary:
[ADR-0009](decisions/ADR-0009-audit-engine-foundation.md).

| Path               | Purpose                                                                                                                                                                                                                                     |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `AuditEngine.php`  | Orchestrates registry → applicability/availability → plan → execution → result.                                                                                                                                                             |
| `AuditContext.php` | What an analyzer needs: run id, project path, `ProjectProfile`, execution settings.                                                                                                                                                         |
| `Contracts/`       | `Analyzer` interface, `AnalyzerId`, `AnalyzerCategory`, `Applicability`/`ApplicabilityStatus`, `Availability`/`AvailabilityStatus`.                                                                                                         |
| `Registry/`        | `AnalyzerRegistry` (explicit registration, duplicate-id guard, deterministic order), `DuplicateAnalyzerIdException`.                                                                                                                        |
| `Plan/`            | `AuditPlan`, `AuditPlanItem` — inspectable, JSON-safe, built without executing anything.                                                                                                                                                    |
| `Execution/`       | `ExecutionStatus`, `AnalyzerResult`, `AnalyzerDiagnostic`/`DiagnosticLevel`, `AnalyzerCoverage`/`CoverageMode` (Phase 3.1 — what an analyzer declares it actually verified, separate from `status`), `AnalyzerExecution`, `AuditRunResult`. |
| `Process/`         | `ProcessRunner` (interface, no implementation), `ProcessCommand`, `ProcessResult` — the future real-process-execution boundary (Phase 4+).                                                                                                  |

No real `Analyzer` is registered anywhere in production; synthetic ones
for exercising the engine live under `tests/Support/Engine/Analyzers/`.

### Findings (`app/Audit/Findings/`, `app/Models/Audit/`)

The persistent domain: a `Finding`'s stable identity, its per-scan
`FindingOccurrence` evidence, and the lifecycle service that safely
auto-resolves/reopens findings. Full detail, security boundary, and
auto-resolution safety:
[`../auditing/findings-lifecycle.md`](../auditing/findings-lifecycle.md);
the decision behind identity/fingerprinting/lifecycle:
[ADR-0010](decisions/ADR-0010-finding-identity-occurrences-and-lifecycle.md).

| Path                                                                                              | Purpose                                                                                                                   |
| ------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| `app/Models/Audit/Project.php`                                                                    | Something auditable registered with LaraDogs.                                                                             |
| `app/Models/Audit/Scan.php`                                                                       | One immutable audit run — `ProjectProfile` snapshot, status, timing.                                                      |
| `app/Models/Audit/ScanAnalyzerExecution.php`                                                      | Persisted `AnalyzerExecution` (Phase 2) per scan, including declared `coverage` — what auto-resolution safety depends on. |
| `app/Models/Audit/Casts/AsAnalyzerCoverage.php`                                                   | Eloquent cast: `AnalyzerCoverage` (Phase 2) &lt;-&gt; JSON, defaulting to `Unknown` on anything unparseable.              |
| `app/Models/Audit/Finding.php`                                                                    | The stable, cross-scan logical identity of an issue.                                                                      |
| `app/Models/Audit/FindingOccurrence.php`                                                          | Evidence observed for a Finding in one specific scan.                                                                     |
| `app/Models/Audit/FindingStatusHistory.php`                                                       | Append-only lifecycle transition audit trail.                                                                             |
| `Findings/FindingCandidate.php`                                                                   | The scanner-agnostic "an analyzer observed this" DTO — the seam a real Phase 4+ analyzer targets.                         |
| `Findings/Severity.php`, `Confidence.php`, `FindingStatus.php`, `ScanStatus.php`, `ActorType.php` | Domain enums.                                                                                                             |
| `Findings/Fingerprint/Fingerprinter.php`                                                          | Versioned (`v1`), line-number-independent identity computation.                                                           |
| `Findings/Redaction/EvidenceRedactor.php`                                                         | Defense-in-depth secret masking for persisted evidence.                                                                   |
| `Findings/Lifecycle/FindingLifecycleService.php`                                                  | The only code path allowed to change a Finding's status.                                                                  |
| `Findings/Ingestion/FindingIngestor.php`, `FindingReconciler.php`, `ScanRecorder.php`             | Find-or-create + occurrence recording, safe auto-resolution sweep, and the Phase 1+2+3 tie-together.                      |

No real scanner produces a `FindingCandidate` yet — that's Phase 4.
`config/laradogs.php` holds a placeholder `version` string recorded on
every scan (no release/tagging scheme exists yet).

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

- Pest, under `tests/Feature` and `tests/Unit`. Starter-kit auth flows —
  39 tests from Phase 0 — Project Discovery
  (`tests/Unit/Audit/Discovery/`, `tests/Feature/Console/`) — 26 tests
  from Phase 1 — the Audit Engine (`tests/Unit/Audit/Engine/`,
  `tests/Feature/Audit/Engine/`) — 38 tests from Phase 2/3.1 (including
  `AnalyzerCoverage`) — and the Finding domain
  (`tests/Unit/Audit/Findings/`, `tests/Feature/Audit/Findings/`) — 58
  tests from Phase 3/3.1, covering ingestion, fingerprinting, lifecycle,
  coverage-gated auto-resolution safety, and the create/create
  concurrency guarantee. 161 tests total, all passing. See
  [`../development/testing.md`](../development/testing.md).
- `tests/Support/Engine/Analyzers/` — synthetic `Analyzer` implementations
  (never autoloaded in production) used only by the Audit Engine's tests.
- `tests/Support/Findings/SyntheticCandidates.php` — synthetic
  `FindingCandidate`s (SQL injection, N+1, vulnerable dependency, config
  issue) used only by the Finding domain's tests — no real scanner exists.
- `tests/Fixtures/discovery/` — small, synthetic project fixtures (never
  real projects) used only by Discovery's tests.

## Tooling already wired by the starter kit

- **Pint** (PHP formatting) — `composer lint` / `composer lint:check`.
- **Larastan/PHPStan** (static analysis) — `composer types:check`.
- **ESLint + Prettier (via `vp check`)** — `npm run check`.
- **Composer `test` script** chains config-clear → lint:check → types:check
  → `php artisan test`, giving one command for the full local gate.

None of this is LaraDogs-specific tooling — it's the standard starter-kit
developer experience, reused as-is rather than replaced (see
[`../development/conventions.md`](../development/conventions.md)).
