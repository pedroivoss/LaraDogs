# Roadmap

## Phases

| Phase | Name                                    | Status                                                              |
| ----- | --------------------------------------- | ------------------------------------------------------------------- |
| 0     | Discovery / Architecture / Bootstrap    | **Complete**                                                        |
| 1     | Project Discovery (stack detection)     | **Complete**                                                        |
| 2     | Audit Engine Foundation                 | **Complete**                                                        |
| 3     | Finding Domain + Persistence            | **Complete**                                                        |
| 4     | Security / Dependency Scanners          | **In progress** (`composer audit` done; other scanners not started) |
| 5     | Bug / Quality Analysis                  | Not started                                                         |
| 6     | Performance Analysis                    | Not started                                                         |
| 7     | Dashboard                               | Not started                                                         |
| 8     | History / Comparison / Quality Gates    | Not started                                                         |
| 9     | MCP                                     | Not started                                                         |
| 10    | Authentication / MCP Credentials        | Not started                                                         |
| 11    | Git Integration / Continuous Monitoring | Not started                                                         |
| 12    | CI / GitHub Action                      | Not started                                                         |
| 13    | Hardening / Release                     | Not started                                                         |

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
