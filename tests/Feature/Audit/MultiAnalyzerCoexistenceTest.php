<?php

use App\Audit\Analyzers\Composer\ComposerAuditAnalyzer;
use App\Audit\Analyzers\Composer\ComposerAuditParser;
use App\Audit\Analyzers\Composer\ComposerBinaryResolver;
use App\Audit\Analyzers\Npm\NpmAuditAnalyzer;
use App\Audit\Analyzers\Npm\NpmAuditParser;
use App\Audit\Analyzers\Npm\NpmBinaryResolver;
use App\Audit\Discovery\ProjectDiscovery;
use App\Audit\Engine\AuditContext;
use App\Audit\Engine\AuditEngine;
use App\Audit\Engine\Contracts\AnalyzerId;
use App\Audit\Engine\Process\ProcessResult;
use App\Audit\Engine\Registry\AnalyzerRegistry;
use App\Audit\Findings\Fingerprint\Fingerprinter;
use App\Audit\Findings\Ingestion\FindingIngestor;
use App\Audit\Findings\Ingestion\FindingReconciler;
use App\Audit\Findings\Ingestion\ScanRecorder;
use App\Audit\Findings\Ingestion\ScanRunner;
use App\Audit\Findings\Lifecycle\FindingLifecycleService;
use App\Audit\Findings\Redaction\EvidenceRedactor;
use App\Audit\Findings\ScanStatus;
use App\Models\Audit\Finding;
use App\Models\Audit\Project;
use App\Models\Audit\ScanAnalyzerExecution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Process\FakeProcessRunner;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Phase 4.2 requirement: a Laravel full-stack project has BOTH Composer
 * and npm dependencies, so both real analyzers must coexist
 * deterministically in the same AnalyzerRegistry/Scan — no id collision,
 * and one analyzer failing must not corrupt or block the other's result
 * (continueOnFailure defaults to true).
 */
function multiAnalyzerContext(): AuditContext
{
    $discovery = (new ProjectDiscovery)->discover(dirname(__DIR__, 2).'/Fixtures/discovery/laravel-inertia-react-ts');
    expect($discovery->isSuccessful())->toBeTrue();

    return new AuditContext(runId: 'multi-analyzer-run', projectPath: $discovery->path, profile: $discovery->profile);
}

function makeScanRunnerFor(AnalyzerRegistry $registry): ScanRunner
{
    $recorder = new ScanRecorder(
        new FindingIngestor(new Fingerprinter, new EvidenceRedactor, new FindingLifecycleService),
        new FindingReconciler(new FindingLifecycleService),
    );

    return new ScanRunner(new AuditEngine($registry), $registry, $recorder);
}

it('both composer-audit and npm-audit are applicable to the same full-stack project', function () {
    $context = multiAnalyzerContext();

    $composerAnalyzer = new ComposerAuditAnalyzer(new ComposerBinaryResolver, new ComposerAuditParser, new FakeProcessRunner);
    $npmAnalyzer = new NpmAuditAnalyzer(new NpmBinaryResolver, new NpmAuditParser, new FakeProcessRunner);

    expect($composerAnalyzer->applicability($context->profile)->isApplicable())->toBeTrue();
    expect($npmAnalyzer->applicability($context->profile)->isApplicable())->toBeTrue();
});

it('registers both analyzers under distinct, non-colliding ids in the same registry', function () {
    $registry = new AnalyzerRegistry;
    $registry->register(new ComposerAuditAnalyzer(new ComposerBinaryResolver, new ComposerAuditParser, new FakeProcessRunner));
    $registry->register(new NpmAuditAnalyzer(new NpmBinaryResolver, new NpmAuditParser, new FakeProcessRunner));

    expect($registry->count())->toBe(2)
        ->and($registry->has(new AnalyzerId('composer-audit')))->toBeTrue()
        ->and($registry->has(new AnalyzerId('npm-audit')))->toBeTrue();
});

it('runs both analyzers in one scan and persists Findings from both, independently identified', function () {
    config(['laradogs.composer.binary' => PHP_BINARY]);
    config(['laradogs.npm.binary' => PHP_BINARY]);

    $composerRunner = new FakeProcessRunner(
        new ProcessResult(0, 'Composer version 2.8.1 2024-11-08 16:39:55', '', false, false, 5),
        new ProcessResult(1, file_get_contents(dirname(__DIR__, 2).'/Fixtures/composer-audit/with-advisories.json'), '', false, false, 10),
    );
    $npmRunner = new FakeProcessRunner(
        new ProcessResult(0, '10.9.7', '', false, false, 5),
        new ProcessResult(1, file_get_contents(dirname(__DIR__, 2).'/Fixtures/npm-audit/with-direct-vulnerability.json'), '', false, false, 10),
    );

    $registry = new AnalyzerRegistry;
    $registry->register(new ComposerAuditAnalyzer(new ComposerBinaryResolver, new ComposerAuditParser, $composerRunner));
    $registry->register(new NpmAuditAnalyzer(new NpmBinaryResolver, new NpmAuditParser, $npmRunner));

    $context = multiAnalyzerContext();
    $project = Project::query()->create(['name' => 'Multi-analyzer fixture', 'path' => $context->projectPath]);

    $scan = makeScanRunnerFor($registry)->runForProject($project, $context);

    expect($scan->status)->toBe(ScanStatus::Completed);
    expect(ScanAnalyzerExecution::query()->where('scan_id', $scan->id)->count())->toBe(2);

    $composerFindings = Finding::query()->where('analyzer_id', 'composer-audit')->get();
    $npmFindings = Finding::query()->where('analyzer_id', 'npm-audit')->get();

    expect($composerFindings)->toHaveCount(2);
    expect($npmFindings)->toHaveCount(2);

    // Rule identity never collides across analyzers even if a coincidental
    // package/id pair matched — analyzer_id is part of each Finding's own
    // identity, not just rule_id.
    expect($composerFindings->pluck('analyzer_id')->unique()->all())->toBe(['composer-audit']);
    expect($npmFindings->pluck('analyzer_id')->unique()->all())->toBe(['npm-audit']);
});

it('one analyzer failing does not corrupt or block the other analyzer\'s result', function () {
    config(['laradogs.composer.binary' => PHP_BINARY]);
    config(['laradogs.npm.binary' => PHP_BINARY]);

    // Composer's audit call returns malformed JSON -> Failed.
    $composerRunner = new FakeProcessRunner(
        new ProcessResult(0, 'Composer version 2.8.1 2024-11-08 16:39:55', '', false, false, 5),
        new ProcessResult(0, '{not valid json', '', false, false, 10),
    );
    // npm's audit call returns a clean, valid report -> Passed.
    $npmRunner = new FakeProcessRunner(
        new ProcessResult(0, '10.9.7', '', false, false, 5),
        new ProcessResult(0, file_get_contents(dirname(__DIR__, 2).'/Fixtures/npm-audit/with-direct-vulnerability.json'), '', false, false, 10),
    );

    $registry = new AnalyzerRegistry;
    $registry->register(new ComposerAuditAnalyzer(new ComposerBinaryResolver, new ComposerAuditParser, $composerRunner));
    $registry->register(new NpmAuditAnalyzer(new NpmBinaryResolver, new NpmAuditParser, $npmRunner));

    $context = multiAnalyzerContext();
    $project = Project::query()->create(['name' => 'Multi-analyzer partial-failure fixture', 'path' => $context->projectPath]);

    $scan = makeScanRunnerFor($registry)->runForProject($project, $context);

    // The scan as a whole still completes (continueOnFailure defaults to
    // true) — an analyzer-level Failed is not the same as a Scan-level
    // failure.
    expect($scan->status)->toBe(ScanStatus::Completed);

    $composerExecution = ScanAnalyzerExecution::query()->where('scan_id', $scan->id)->where('analyzer_id', 'composer-audit')->first();
    $npmExecution = ScanAnalyzerExecution::query()->where('scan_id', $scan->id)->where('analyzer_id', 'npm-audit')->first();

    expect($composerExecution->status->value)->toBe('failed');
    expect($npmExecution->status->value)->toBe('passed');

    // npm's findings were persisted normally, unaffected by Composer's failure.
    expect(Finding::query()->where('analyzer_id', 'npm-audit')->count())->toBe(2);
    expect(Finding::query()->where('analyzer_id', 'composer-audit')->count())->toBe(0);
});
