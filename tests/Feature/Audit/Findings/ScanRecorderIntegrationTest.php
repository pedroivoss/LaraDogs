<?php

use App\Audit\Discovery\ProjectDiscovery;
use App\Audit\Engine\AuditContext;
use App\Audit\Engine\AuditEngine;
use App\Audit\Engine\Contracts\AnalyzerId;
use App\Audit\Engine\Execution\AnalyzerCoverage;
use App\Audit\Engine\Registry\AnalyzerRegistry;
use App\Audit\Findings\FindingStatus;
use App\Audit\Findings\Fingerprint\Fingerprinter;
use App\Audit\Findings\Ingestion\FindingIngestor;
use App\Audit\Findings\Ingestion\FindingReconciler;
use App\Audit\Findings\Ingestion\ScanRecorder;
use App\Audit\Findings\Lifecycle\FindingLifecycleService;
use App\Audit\Findings\Redaction\EvidenceRedactor;
use App\Audit\Findings\ScanStatus;
use App\Models\Audit\Finding;
use App\Models\Audit\Project;
use App\Models\Audit\ScanAnalyzerExecution;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Engine\Analyzers\AlwaysFailAnalyzer;
use Tests\Support\Engine\Analyzers\AlwaysPassAnalyzer;
use Tests\Support\Engine\Analyzers\NotApplicableAnalyzer;
use Tests\Support\Engine\Analyzers\UnavailableAnalyzer;
use Tests\Support\Findings\SyntheticCandidates;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeScanRecorder(): ScanRecorder
{
    return new ScanRecorder(
        new FindingIngestor(new Fingerprinter, new EvidenceRedactor, new FindingLifecycleService),
        new FindingReconciler(new FindingLifecycleService),
    );
}

function buildRealAuditContext(string $fixture, string $runId): AuditContext
{
    $discovery = (new ProjectDiscovery)->discover(dirname(__DIR__, 3).'/Fixtures/discovery/'.$fixture);
    expect($discovery->isSuccessful())->toBeTrue();

    return new AuditContext(runId: $runId, projectPath: $discovery->path, profile: $discovery->profile);
}

it('records a full scan end-to-end: real Discovery + real Engine + Phase 3 persistence', function () {
    $registry = new AnalyzerRegistry;
    $registry->register(new AlwaysPassAnalyzer('composer-security'));
    $registry->register(new AlwaysFailAnalyzer('semgrep'));
    $registry->register(new UnavailableAnalyzer('npm-audit'));

    $context = buildRealAuditContext('laravel-blade', 'run-1');
    $runResult = (new AuditEngine($registry))->run($context);

    $project = Project::query()->create(['name' => 'Example', 'path' => $context->projectPath]);
    $recorder = makeScanRecorder();

    $scan = $recorder->startScan($project, $context->profile);
    expect($scan->status)->toBe(ScanStatus::Running);

    $completed = $recorder->completeScan($scan, $runResult, [
        'composer-security' => [SyntheticCandidates::sqlInjection()],
    ]);

    expect($completed->status)->toBe(ScanStatus::Completed);
    expect($completed->finished_at)->not->toBeNull();
    expect(ScanAnalyzerExecution::query()->where('scan_id', $scan->id)->count())->toBe(3);
    expect(Finding::query()->count())->toBe(1);
    expect($completed->findings_summary['observed'])->toBe(1);
    expect($completed->project_profile['project']['type'])->toBe('laravel');
});

it('auto-resolves a finding across two full scans once its analyzer stops reporting it', function () {
    $registry = new AnalyzerRegistry;
    // Coverage must be declared explicitly — a Passed status alone is not
    // enough since Phase 3.1 (see FindingReconciler).
    $registry->register(new AlwaysPassAnalyzer('composer-security', AnalyzerCoverage::explicit(['LARA-SEC-023'])));
    $recorder = makeScanRecorder();

    $contextOne = buildRealAuditContext('laravel-blade', 'run-a');
    $project = Project::query()->create(['name' => 'Example', 'path' => $contextOne->projectPath]);

    $scanOne = $recorder->startScan($project, $contextOne->profile);
    $runResultOne = (new AuditEngine($registry))->run($contextOne);
    $recorder->completeScan($scanOne, $runResultOne, [
        'composer-security' => [SyntheticCandidates::sqlInjection()],
    ]);

    expect(Finding::query()->firstOrFail()->status)->toBe(FindingStatus::Open);

    $contextTwo = buildRealAuditContext('laravel-blade', 'run-b');
    $scanTwo = $recorder->startScan($project, $contextTwo->profile);
    $runResultTwo = (new AuditEngine($registry))->run($contextTwo);
    // The analyzer runs successfully again but reports nothing this time.
    $recorder->completeScan($scanTwo, $runResultTwo, []);

    expect(Finding::query()->firstOrFail()->status)->toBe(FindingStatus::Resolved);
});

it('does not auto-resolve across two full scans when the analyzer passed but its rule was disabled/removed from coverage', function () {
    // This is the exact gap Phase 3.1 closes: the analyzer keeps passing,
    // but its second run's coverage no longer names the rule behind the
    // finding (e.g. the rule was disabled/removed from this ruleset) — the
    // finding not reappearing must NOT be read as "fixed."
    $registryOne = new AnalyzerRegistry;
    $registryOne->register(new AlwaysPassAnalyzer('composer-security', AnalyzerCoverage::explicit(['LARA-SEC-023'])));

    $contextOne = buildRealAuditContext('laravel-blade', 'run-rule-removed-a');
    $project = Project::query()->create(['name' => 'Example', 'path' => $contextOne->projectPath]);
    $recorder = makeScanRecorder();

    $scanOne = $recorder->startScan($project, $contextOne->profile);
    $recorder->completeScan($scanOne, (new AuditEngine($registryOne))->run($contextOne), [
        'composer-security' => [SyntheticCandidates::sqlInjection()],
    ]);

    $registryTwo = new AnalyzerRegistry;
    // Same analyzer id, still Passed — but LARA-SEC-023 is no longer in
    // its declared coverage (disabled/removed from this ruleset).
    $registryTwo->register(new AlwaysPassAnalyzer('composer-security', AnalyzerCoverage::explicit(['LARA-SEC-001'])));

    $contextTwo = buildRealAuditContext('laravel-blade', 'run-rule-removed-b');
    $scanTwo = $recorder->startScan($project, $contextTwo->profile);
    $recorder->completeScan($scanTwo, (new AuditEngine($registryTwo))->run($contextTwo), []);

    expect(Finding::query()->firstOrFail()->status)->toBe(FindingStatus::Open);
});

it('does not auto-resolve when the only analyzer that ran is not applicable to this project', function () {
    $registry = new AnalyzerRegistry;
    $registry->register(new AlwaysPassAnalyzer('composer-security'));
    $recorder = makeScanRecorder();

    $contextOne = buildRealAuditContext('laravel-blade', 'run-c');
    $project = Project::query()->create(['name' => 'Example', 'path' => $contextOne->projectPath]);
    $scanOne = $recorder->startScan($project, $contextOne->profile);
    $recorder->completeScan($scanOne, (new AuditEngine($registry))->run($contextOne), [
        'composer-security' => [SyntheticCandidates::sqlInjection()],
    ]);

    $registryTwo = new AnalyzerRegistry;
    $registryTwo->register(new NotApplicableAnalyzer);

    $contextTwo = buildRealAuditContext('laravel-blade', 'run-d');
    $scanTwo = $recorder->startScan($project, $contextTwo->profile);
    $recorder->completeScan($scanTwo, (new AuditEngine($registryTwo))->run($contextTwo), []);

    expect(Finding::query()->firstOrFail()->status)->toBe(FindingStatus::Open);
});

it('marks the scan Failed and does not leave partial data if something goes wrong mid-ingestion', function () {
    $registry = new AnalyzerRegistry;
    $registry->register(new AlwaysPassAnalyzer('composer-security'));

    $context = buildRealAuditContext('laravel-blade', 'run-e');
    $project = Project::query()->create(['name' => 'Example', 'path' => $context->projectPath]);
    $recorder = makeScanRecorder();

    $scan = $recorder->startScan($project, $context->profile);
    $runResult = (new AuditEngine($registry))->run($context);

    // Simulate the project disappearing mid-scan (e.g. a concurrent
    // deletion) — the project's cascade delete removes the scan row too,
    // so persisting anything against this now-stale $scan reference must
    // fail loudly rather than silently succeed or hang in "running".
    Project::query()->where('id', $project->id)->delete();

    expect(fn () => $recorder->completeScan($scan, $runResult, []))->toThrow(QueryException::class);
    expect($scan->status)->toBe(ScanStatus::Failed);
    expect(Finding::query()->count())->toBe(0);
});

it('never executes composer.json/package.json scripts in the analyzed project across the full pipeline', function () {
    $filesystem = new Filesystem;
    $source = dirname(__DIR__, 3).'/Fixtures/discovery/no-code-execution';
    $target = sys_get_temp_dir().'/laradogs-findings-no-exec-'.uniqid();
    $filesystem->copyDirectory($source, $target);

    try {
        $discovery = (new ProjectDiscovery)->discover($target);
        expect($discovery->isSuccessful())->toBeTrue();

        $registry = new AnalyzerRegistry;
        $registry->register(new AlwaysPassAnalyzer);

        $context = new AuditContext(runId: 'no-exec-run', projectPath: $target, profile: $discovery->profile);
        $runResult = (new AuditEngine($registry))->run($context);

        $project = Project::query()->create(['name' => 'NoExec', 'path' => $target]);
        $recorder = makeScanRecorder();
        $scan = $recorder->startScan($project, $discovery->profile);
        $recorder->completeScan($scan, $runResult, [
            (string) new AnalyzerId('test.always-pass') => [SyntheticCandidates::sqlInjection(filePath: 'irrelevant.php')],
        ]);

        expect($filesystem->exists($target.'/SHOULD_NEVER_EXIST'))->toBeFalse();
        expect($filesystem->exists($source.'/SHOULD_NEVER_EXIST'))->toBeFalse();
    } finally {
        $filesystem->deleteDirectory($target);
    }
});
