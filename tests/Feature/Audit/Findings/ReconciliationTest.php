<?php

use App\Audit\Engine\Contracts\AnalyzerCategory;
use App\Audit\Engine\Execution\AnalyzerCoverage;
use App\Audit\Engine\Execution\ExecutionStatus;
use App\Audit\Findings\ActorType;
use App\Audit\Findings\FindingStatus;
use App\Audit\Findings\Fingerprint\Fingerprinter;
use App\Audit\Findings\Ingestion\FindingIngestor;
use App\Audit\Findings\Ingestion\FindingReconciler;
use App\Audit\Findings\Lifecycle\FindingLifecycleService;
use App\Audit\Findings\Redaction\EvidenceRedactor;
use App\Models\Audit\Finding;
use App\Models\Audit\Project;
use App\Models\Audit\Scan;
use App\Models\Audit\ScanAnalyzerExecution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Findings\SyntheticCandidates;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// SyntheticCandidates::sqlInjection() always uses this rule id.
const RECONCILIATION_RULE_ID = 'LARA-SEC-023';

function reconciliationIngestor(): FindingIngestor
{
    return new FindingIngestor(new Fingerprinter, new EvidenceRedactor, new FindingLifecycleService);
}

function reconciliationProject(): Project
{
    return Project::query()->create(['name' => 'Example', 'path' => '/workspace/example']);
}

function reconciliationScan(Project $project): Scan
{
    return Scan::query()->create([
        'project_id' => $project->id,
        'status' => 'running',
        'started_at' => now(),
        'project_profile' => ['project' => ['type' => 'laravel']],
    ]);
}

function recordAnalyzerExecution(
    Scan $scan,
    string $analyzerId,
    ExecutionStatus $status,
    ?AnalyzerCoverage $coverage = null,
): ScanAnalyzerExecution {
    return ScanAnalyzerExecution::query()->create([
        'scan_id' => $scan->id,
        'analyzer_id' => $analyzerId,
        'analyzer_name' => $analyzerId,
        'category' => AnalyzerCategory::Security,
        'status' => $status,
        'coverage' => $coverage ?? AnalyzerCoverage::unknown(),
    ]);
}

// 1. PASSED + EXPLICIT coverage containing the rule id + finding absent -> RESOLVED.
it('auto-resolves a finding when its analyzer passed and its EXPLICIT coverage names the rule', function () {
    $project = reconciliationProject();
    $scanOne = reconciliationScan($project);
    reconciliationIngestor()->ingest($project, $scanOne, SyntheticCandidates::sqlInjection());

    $scanTwo = reconciliationScan($project);
    recordAnalyzerExecution($scanTwo, 'composer-security', ExecutionStatus::Passed, AnalyzerCoverage::explicit([RECONCILIATION_RULE_ID]));

    $resolvedCount = (new FindingReconciler(new FindingLifecycleService))->reconcile($project, $scanTwo);

    expect($resolvedCount)->toBe(1);
    expect(Finding::query()->firstOrFail()->status)->toBe(FindingStatus::Resolved);
});

// 2. PASSED + EXPLICIT coverage NOT containing the rule id -> NOT RESOLVED.
it('does not auto-resolve when EXPLICIT coverage does not name the finding\'s rule', function () {
    $project = reconciliationProject();
    $scanOne = reconciliationScan($project);
    reconciliationIngestor()->ingest($project, $scanOne, SyntheticCandidates::sqlInjection());

    $scanTwo = reconciliationScan($project);
    recordAnalyzerExecution($scanTwo, 'composer-security', ExecutionStatus::Passed, AnalyzerCoverage::explicit(['LARA-SEC-999']));

    $resolvedCount = (new FindingReconciler(new FindingLifecycleService))->reconcile($project, $scanTwo);

    expect($resolvedCount)->toBe(0);
    expect(Finding::query()->firstOrFail()->status)->toBe(FindingStatus::Open);
});

// 3. PASSED + UNKNOWN coverage -> NOT RESOLVED. This is the exact gap
// Phase 3.1 closes: the analyzer ran cleanly but said nothing about what
// it verified (e.g. the rule was removed/disabled/not loaded this run).
it('does not auto-resolve on PASSED alone when coverage is UNKNOWN', function () {
    $project = reconciliationProject();
    $scanOne = reconciliationScan($project);
    reconciliationIngestor()->ingest($project, $scanOne, SyntheticCandidates::sqlInjection());

    $scanTwo = reconciliationScan($project);
    recordAnalyzerExecution($scanTwo, 'composer-security', ExecutionStatus::Passed, AnalyzerCoverage::unknown());

    $resolvedCount = (new FindingReconciler(new FindingLifecycleService))->reconcile($project, $scanTwo);

    expect($resolvedCount)->toBe(0);
    expect(Finding::query()->firstOrFail()->status)->toBe(FindingStatus::Open);
});

// 4. PASSED + no coverage information supplied at all -> NOT RESOLVED
// (defaults to UNKNOWN, never implicitly upgraded because status is Passed).
it('does not auto-resolve on PASSED alone when no coverage was ever reported', function () {
    $project = reconciliationProject();
    $scanOne = reconciliationScan($project);
    reconciliationIngestor()->ingest($project, $scanOne, SyntheticCandidates::sqlInjection());

    $scanTwo = reconciliationScan($project);
    recordAnalyzerExecution($scanTwo, 'composer-security', ExecutionStatus::Passed); // no $coverage argument at all

    $resolvedCount = (new FindingReconciler(new FindingLifecycleService))->reconcile($project, $scanTwo);

    expect($resolvedCount)->toBe(0);
    expect(Finding::query()->firstOrFail()->status)->toBe(FindingStatus::Open);
    expect(ScanAnalyzerExecution::query()->latest('id')->first()->coverage->mode->value)->toBe('unknown');
});

// 5. FAILED + coverage containing the rule -> NOT RESOLVED. Execution
// status still gates resolution even when coverage alone would allow it.
it('does not resolve a finding when its analyzer failed, even with matching coverage', function () {
    $project = reconciliationProject();
    $scanOne = reconciliationScan($project);
    reconciliationIngestor()->ingest($project, $scanOne, SyntheticCandidates::sqlInjection());

    $scanTwo = reconciliationScan($project);
    recordAnalyzerExecution($scanTwo, 'composer-security', ExecutionStatus::Failed, AnalyzerCoverage::explicit([RECONCILIATION_RULE_ID]));

    $resolvedCount = (new FindingReconciler(new FindingLifecycleService))->reconcile($project, $scanTwo);

    expect($resolvedCount)->toBe(0);
    expect(Finding::query()->firstOrFail()->status)->toBe(FindingStatus::Open);
});

// 6. TIMED_OUT + coverage containing the rule -> NOT RESOLVED.
it('does not resolve a finding when its analyzer timed out, even with matching coverage', function () {
    $project = reconciliationProject();
    $scanOne = reconciliationScan($project);
    reconciliationIngestor()->ingest($project, $scanOne, SyntheticCandidates::sqlInjection());

    $scanTwo = reconciliationScan($project);
    recordAnalyzerExecution($scanTwo, 'composer-security', ExecutionStatus::TimedOut, AnalyzerCoverage::explicit([RECONCILIATION_RULE_ID]));

    $resolvedCount = (new FindingReconciler(new FindingLifecycleService))->reconcile($project, $scanTwo);

    expect($resolvedCount)->toBe(0);
    expect(Finding::query()->firstOrFail()->status)->toBe(FindingStatus::Open);
});

// 7. UNAVAILABLE + coverage containing the rule -> NOT RESOLVED.
it('does not resolve a finding when its analyzer was unavailable, even with matching coverage', function () {
    $project = reconciliationProject();
    $scanOne = reconciliationScan($project);
    reconciliationIngestor()->ingest($project, $scanOne, SyntheticCandidates::sqlInjection());

    $scanTwo = reconciliationScan($project);
    recordAnalyzerExecution($scanTwo, 'composer-security', ExecutionStatus::Unavailable, AnalyzerCoverage::explicit([RECONCILIATION_RULE_ID]));

    $resolvedCount = (new FindingReconciler(new FindingLifecycleService))->reconcile($project, $scanTwo);

    expect($resolvedCount)->toBe(0);
    expect(Finding::query()->firstOrFail()->status)->toBe(FindingStatus::Open);
});

it('does not resolve a finding when its analyzer simply did not run in this scan', function () {
    $project = reconciliationProject();
    $scanOne = reconciliationScan($project);
    reconciliationIngestor()->ingest($project, $scanOne, SyntheticCandidates::sqlInjection());

    // scanTwo records no analyzer execution at all for composer-security —
    // e.g. it was dropped from the registry or the plan.
    $scanTwo = reconciliationScan($project);

    $resolvedCount = (new FindingReconciler(new FindingLifecycleService))->reconcile($project, $scanTwo);

    expect($resolvedCount)->toBe(0);
    expect(Finding::query()->firstOrFail()->status)->toBe(FindingStatus::Open);
});

// 8. FULL coverage explicitly declared + PASSED -> absence can resolve,
// regardless of the specific rule id (Full means "the entire relevant
// domain was verified").
it('auto-resolves under FULL coverage without needing the rule id explicitly listed', function () {
    $project = reconciliationProject();
    $scanOne = reconciliationScan($project);
    reconciliationIngestor()->ingest($project, $scanOne, SyntheticCandidates::sqlInjection());

    $scanTwo = reconciliationScan($project);
    recordAnalyzerExecution($scanTwo, 'composer-security', ExecutionStatus::Passed, AnalyzerCoverage::full());

    $resolvedCount = (new FindingReconciler(new FindingLifecycleService))->reconcile($project, $scanTwo);

    expect($resolvedCount)->toBe(1);
    expect(Finding::query()->firstOrFail()->status)->toBe(FindingStatus::Resolved);
});

// 9. Different ruleset_version, but coverage still names the rule ->
// resolves. The decision is driven by coverage, never by version alone.
it('resolves based on coverage even when ruleset_version differs from any prior run', function () {
    $project = reconciliationProject();
    $scanOne = reconciliationScan($project);
    reconciliationIngestor()->ingest($project, $scanOne, SyntheticCandidates::sqlInjection());
    recordAnalyzerExecution($scanOne, 'composer-security', ExecutionStatus::Passed, AnalyzerCoverage::explicit([RECONCILIATION_RULE_ID], rulesetVersion: '2026.01.1'));

    $scanTwo = reconciliationScan($project);
    recordAnalyzerExecution($scanTwo, 'composer-security', ExecutionStatus::Passed, AnalyzerCoverage::explicit([RECONCILIATION_RULE_ID], rulesetVersion: '2026.09.1'));

    $resolvedCount = (new FindingReconciler(new FindingLifecycleService))->reconcile($project, $scanTwo);

    expect($resolvedCount)->toBe(1);
    expect(Finding::query()->firstOrFail()->status)->toBe(FindingStatus::Resolved);
});

// 10. Same ruleset_version, but coverage does not name the rule -> NOT
// RESOLVED. Proves version equality never substitutes for actual coverage.
it('does not resolve when coverage omits the rule, even under the same ruleset_version as before', function () {
    $project = reconciliationProject();
    $scanOne = reconciliationScan($project);
    reconciliationIngestor()->ingest($project, $scanOne, SyntheticCandidates::sqlInjection());
    recordAnalyzerExecution($scanOne, 'composer-security', ExecutionStatus::Passed, AnalyzerCoverage::explicit([RECONCILIATION_RULE_ID], rulesetVersion: '2026.09.1'));

    $scanTwo = reconciliationScan($project);
    recordAnalyzerExecution($scanTwo, 'composer-security', ExecutionStatus::Passed, AnalyzerCoverage::explicit(['LARA-SEC-999'], rulesetVersion: '2026.09.1'));

    $resolvedCount = (new FindingReconciler(new FindingLifecycleService))->reconcile($project, $scanTwo);

    expect($resolvedCount)->toBe(0);
    expect(Finding::query()->firstOrFail()->status)->toBe(FindingStatus::Open);
});

// 11. A finding belonging to a different analyzer is never touched by
// another analyzer's coverage, however permissive.
it('never lets one analyzer\'s coverage resolve a finding belonging to a different analyzer', function () {
    $project = reconciliationProject();
    $scanOne = reconciliationScan($project);
    reconciliationIngestor()->ingest($project, $scanOne, SyntheticCandidates::sqlInjection(analyzerId: 'composer-security'));

    $scanTwo = reconciliationScan($project);
    // A different analyzer, with maximally permissive coverage, runs —
    // but it is not the analyzer that owns this finding.
    recordAnalyzerExecution($scanTwo, 'semgrep', ExecutionStatus::Passed, AnalyzerCoverage::full());

    $resolvedCount = (new FindingReconciler(new FindingLifecycleService))->reconcile($project, $scanTwo);

    expect($resolvedCount)->toBe(0);
    expect(Finding::query()->firstOrFail()->status)->toBe(FindingStatus::Open);
});

// 12. Suppressed statuses remain untouched regardless of coverage.
it('does not auto-resolve a finding already in a suppressed manual status, even under FULL coverage', function () {
    $project = reconciliationProject();
    $scanOne = reconciliationScan($project);
    reconciliationIngestor()->ingest($project, $scanOne, SyntheticCandidates::sqlInjection());

    $finding = Finding::query()->firstOrFail();
    (new FindingLifecycleService)->transition($finding, FindingStatus::FalsePositive, ActorType::User, 'triager-1', 'Not exploitable here.');

    $scanTwo = reconciliationScan($project);
    recordAnalyzerExecution($scanTwo, 'composer-security', ExecutionStatus::Passed, AnalyzerCoverage::full());

    $resolvedCount = (new FindingReconciler(new FindingLifecycleService))->reconcile($project, $scanTwo);

    expect($resolvedCount)->toBe(0);
    expect($finding->fresh()->status)->toBe(FindingStatus::FalsePositive);
});

it('does not let a new occurrence silently overturn a suppressed manual status', function () {
    $project = reconciliationProject();
    $scanOne = reconciliationScan($project);
    $ingestor = reconciliationIngestor();
    $ingestor->ingest($project, $scanOne, SyntheticCandidates::sqlInjection());

    $finding = Finding::query()->firstOrFail();
    (new FindingLifecycleService)->transition($finding, FindingStatus::AcceptedRisk, ActorType::User, 'triager-1', 'Accepted for now.');

    // The same issue is observed again in a later scan.
    $scanTwo = reconciliationScan($project);
    $ingestor->ingest($project, $scanTwo, SyntheticCandidates::sqlInjection());

    expect($finding->fresh()->status)->toBe(FindingStatus::AcceptedRisk);
    expect($finding->occurrences()->count())->toBe(2);
});

it('reopens a resolved finding when it reappears, recording the regression in history', function () {
    $project = reconciliationProject();
    $ingestor = reconciliationIngestor();

    $scanOne = reconciliationScan($project);
    $ingestor->ingest($project, $scanOne, SyntheticCandidates::sqlInjection());

    $scanTwo = reconciliationScan($project);
    recordAnalyzerExecution($scanTwo, 'composer-security', ExecutionStatus::Passed, AnalyzerCoverage::explicit([RECONCILIATION_RULE_ID]));
    (new FindingReconciler(new FindingLifecycleService))->reconcile($project, $scanTwo);

    $finding = Finding::query()->firstOrFail();
    expect($finding->status)->toBe(FindingStatus::Resolved);

    // The bug is reintroduced and observed again in a third scan.
    $scanThree = reconciliationScan($project);
    $ingestor->ingest($project, $scanThree, SyntheticCandidates::sqlInjection());

    $finding->refresh();
    expect($finding->status)->toBe(FindingStatus::Open);

    $lastEvent = $finding->statusHistory()->latest('id')->first();
    expect($lastEvent->previous_status)->toBe(FindingStatus::Resolved);
    expect($lastEvent->new_status)->toBe(FindingStatus::Open);
    expect($lastEvent->reason)->toContain('Reopened automatically');
    expect($lastEvent->actor_type)->toBe(ActorType::System);
});
