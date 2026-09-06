<?php

use App\Audit\Engine\Contracts\AnalyzerCategory;
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

function recordAnalyzerExecution(Scan $scan, string $analyzerId, ExecutionStatus $status): ScanAnalyzerExecution
{
    return ScanAnalyzerExecution::query()->create([
        'scan_id' => $scan->id,
        'analyzer_id' => $analyzerId,
        'analyzer_name' => $analyzerId,
        'category' => AnalyzerCategory::Security,
        'status' => $status,
    ]);
}

it('auto-resolves a finding whose analyzer ran successfully and no longer reports it', function () {
    $project = reconciliationProject();
    $scanOne = reconciliationScan($project);
    reconciliationIngestor()->ingest($project, $scanOne, SyntheticCandidates::sqlInjection());
    recordAnalyzerExecution($scanOne, 'composer-security', ExecutionStatus::Passed);

    $scanTwo = reconciliationScan($project);
    recordAnalyzerExecution($scanTwo, 'composer-security', ExecutionStatus::Passed);
    // No candidate ingested in scan two — the finding was not re-observed.

    $resolvedCount = (new FindingReconciler(new FindingLifecycleService))->reconcile($project, $scanTwo);

    expect($resolvedCount)->toBe(1);
    expect(Finding::query()->firstOrFail()->status)->toBe(FindingStatus::Resolved);
});

it('does not resolve a finding when its analyzer failed in this scan', function () {
    $project = reconciliationProject();
    $scanOne = reconciliationScan($project);
    reconciliationIngestor()->ingest($project, $scanOne, SyntheticCandidates::sqlInjection());

    $scanTwo = reconciliationScan($project);
    recordAnalyzerExecution($scanTwo, 'composer-security', ExecutionStatus::Failed);

    $resolvedCount = (new FindingReconciler(new FindingLifecycleService))->reconcile($project, $scanTwo);

    expect($resolvedCount)->toBe(0);
    expect(Finding::query()->firstOrFail()->status)->toBe(FindingStatus::Open);
});

it('does not resolve a finding when its analyzer was unavailable in this scan', function () {
    $project = reconciliationProject();
    $scanOne = reconciliationScan($project);
    reconciliationIngestor()->ingest($project, $scanOne, SyntheticCandidates::sqlInjection());

    $scanTwo = reconciliationScan($project);
    recordAnalyzerExecution($scanTwo, 'composer-security', ExecutionStatus::Unavailable);

    $resolvedCount = (new FindingReconciler(new FindingLifecycleService))->reconcile($project, $scanTwo);

    expect($resolvedCount)->toBe(0);
    expect(Finding::query()->firstOrFail()->status)->toBe(FindingStatus::Open);
});

it('does not resolve a finding when its analyzer timed out in this scan', function () {
    $project = reconciliationProject();
    $scanOne = reconciliationScan($project);
    reconciliationIngestor()->ingest($project, $scanOne, SyntheticCandidates::sqlInjection());

    $scanTwo = reconciliationScan($project);
    recordAnalyzerExecution($scanTwo, 'composer-security', ExecutionStatus::TimedOut);

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

it('does not auto-resolve a finding already in a suppressed manual status', function () {
    $project = reconciliationProject();
    $scanOne = reconciliationScan($project);
    reconciliationIngestor()->ingest($project, $scanOne, SyntheticCandidates::sqlInjection());

    $finding = Finding::query()->firstOrFail();
    (new FindingLifecycleService)->transition($finding, FindingStatus::FalsePositive, ActorType::User, 'triager-1', 'Not exploitable here.');

    $scanTwo = reconciliationScan($project);
    recordAnalyzerExecution($scanTwo, 'composer-security', ExecutionStatus::Passed);

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
    recordAnalyzerExecution($scanOne, 'composer-security', ExecutionStatus::Passed);

    $scanTwo = reconciliationScan($project);
    recordAnalyzerExecution($scanTwo, 'composer-security', ExecutionStatus::Passed);
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
