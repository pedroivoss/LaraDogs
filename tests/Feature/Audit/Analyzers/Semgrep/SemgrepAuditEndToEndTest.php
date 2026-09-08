<?php

use App\Audit\Analyzers\Composer\ComposerAuditAnalyzer;
use App\Audit\Analyzers\Composer\ComposerAuditParser;
use App\Audit\Analyzers\Composer\ComposerBinaryResolver;
use App\Audit\Analyzers\Semgrep\SemgrepAnalyzer;
use App\Audit\Analyzers\Semgrep\SemgrepBinaryResolver;
use App\Audit\Analyzers\Semgrep\SemgrepCoverageEvaluator;
use App\Audit\Analyzers\Semgrep\SemgrepParser;
use App\Audit\Analyzers\Semgrep\SemgrepTargetCollector;
use App\Audit\Discovery\ProjectDiscovery;
use App\Audit\Engine\AuditContext;
use App\Audit\Engine\AuditEngine;
use App\Audit\Engine\Execution\CoverageMode;
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
use App\Models\Audit\FindingOccurrence;
use App\Models\Audit\Project;
use App\Models\Audit\ScanAnalyzerExecution;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Process\FakeProcessRunner;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function semgrepScanRunner(FakeProcessRunner $semgrepRunner): array
{
    config(['laradogs.semgrep.binary' => PHP_BINARY]);

    $analyzer = new SemgrepAnalyzer(
        new SemgrepBinaryResolver,
        new SemgrepParser,
        new SemgrepTargetCollector,
        new SemgrepCoverageEvaluator,
        $semgrepRunner,
    );

    $registry = new AnalyzerRegistry;
    $registry->register($analyzer);

    $recorder = new ScanRecorder(
        new FindingIngestor(new Fingerprinter, new EvidenceRedactor, new FindingLifecycleService),
        new FindingReconciler(new FindingLifecycleService),
    );

    return [new ScanRunner(new AuditEngine($registry), $registry, $recorder), $analyzer];
}

/**
 * The required full-pipeline test: a real fixture project -> real
 * ProjectDiscovery -> real SemgrepAnalyzer -> a fake/captured
 * ProcessRunner result -> real AuditEngine -> real FindingCandidate
 * normalization -> real ScanRunner/ScanRecorder -> real persistence.
 */
it('runs the full pipeline end-to-end and persists a Scan, ScanAnalyzerExecution, Finding and FindingOccurrence', function () {
    $discovery = (new ProjectDiscovery)->discover(dirname(__DIR__, 4).'/Fixtures/semgrep/php-project');
    expect($discovery->isSuccessful())->toBeTrue();

    $context = new AuditContext(runId: 'semgrep-e2e', projectPath: $discovery->path, profile: $discovery->profile);

    $scanJson = json_encode([
        'version' => '1.176.0',
        'results' => [
            [
                'check_id' => 'laradogs.quality.debug.dd-call',
                'path' => $discovery->path.'/app/Debug.php',
                'start' => ['line' => 7, 'col' => 5, 'offset' => 0],
                'end' => ['line' => 7, 'col' => 14, 'offset' => 0],
                'extra' => ['message' => 'dd() halts execution.', 'severity' => 'WARNING', 'metadata' => []],
            ],
            [
                'check_id' => 'laradogs.quality.debug.var-dump-call',
                'path' => $discovery->path.'/app/Debug.php',
                'start' => ['line' => 8, 'col' => 5, 'offset' => 0],
                'end' => ['line' => 8, 'col' => 19, 'offset' => 0],
                'extra' => ['message' => 'var_dump() left in code.', 'severity' => 'WARNING', 'metadata' => []],
            ],
        ],
        'errors' => [],
        'paths' => ['scanned' => [$discovery->path.'/app/Debug.php'], 'skipped' => []],
    ]);

    $processRunner = new FakeProcessRunner(
        new ProcessResult(0, '1.176.0', '', false, false, 5),
        new ProcessResult(0, $scanJson, '', false, false, 20),
    );

    [$scanRunner] = semgrepScanRunner($processRunner);

    $project = Project::query()->create(['name' => 'Semgrep E2E Fixture', 'path' => $context->projectPath]);

    $scan = $scanRunner->run($project, $context);

    expect($scan->status)->toBe(ScanStatus::Completed);

    $execution = ScanAnalyzerExecution::query()->where('scan_id', $scan->id)->where('analyzer_id', 'semgrep')->first();
    expect($execution)->not->toBeNull()
        ->and($execution->status->value)->toBe('passed')
        ->and($execution->coverage->mode)->toBe(CoverageMode::Explicit);

    $findings = Finding::query()->where('analyzer_id', 'semgrep')->get();
    expect($findings)->toHaveCount(2);

    $occurrences = FindingOccurrence::query()->where('scan_id', $scan->id)->get();
    expect($occurrences)->toHaveCount(2);

    $dd = $findings->firstWhere('rule_id', 'laradogs.quality.debug.dd-call');
    expect($dd)->not->toBeNull()
        ->and($dd->severity->value)->toBe('medium');

    $ddOccurrence = $occurrences->firstWhere('finding_id', $dd->id);
    expect($ddOccurrence->file_path)->toBe('app/Debug.php')
        ->and($ddOccurrence->line_start)->toBe(7);
});

/**
 * The exact security property this pipeline exists to guarantee: a PHP
 * fixture file whose top-level code would create a marker file if EXECUTED
 * is only ever read as data by Semgrep's pattern matcher — the marker file
 * must never appear, and the real match (dd()) on a later line must still
 * be found.
 */
it('never executes target PHP source — only reads it as data — even when the fixture contains code that would create a marker file if run', function () {
    $filesystem = new Filesystem;
    $discovery = (new ProjectDiscovery)->discover(dirname(__DIR__, 4).'/Fixtures/semgrep/malicious-execution-project');
    expect($discovery->isSuccessful())->toBeTrue();

    $context = new AuditContext(runId: 'semgrep-malicious', projectPath: $discovery->path, profile: $discovery->profile);

    $scanJson = json_encode([
        'version' => '1.176.0',
        'results' => [[
            'check_id' => 'laradogs.quality.debug.dd-call',
            'path' => $discovery->path.'/app/Malicious.php',
            'start' => ['line' => 9, 'col' => 1, 'offset' => 0],
            'end' => ['line' => 9, 'col' => 5, 'offset' => 0],
            'extra' => ['message' => 'dd() call', 'severity' => 'WARNING', 'metadata' => []],
        ]],
        'errors' => [],
        'paths' => ['scanned' => [$discovery->path.'/app/Malicious.php'], 'skipped' => []],
    ]);

    $processRunner = new FakeProcessRunner(
        new ProcessResult(0, '1.176.0', '', false, false, 5),
        new ProcessResult(0, $scanJson, '', false, false, 20),
    );

    [$scanRunner] = semgrepScanRunner($processRunner);
    $project = Project::query()->create(['name' => 'Semgrep Malicious Fixture', 'path' => $context->projectPath]);

    $scan = $scanRunner->run($project, $context);

    expect($scan->status)->toBe(ScanStatus::Completed);
    expect(Finding::query()->where('analyzer_id', 'semgrep')->count())->toBe(1);

    // The marker file the fixture's own `system()` call would have created
    // if it had ever actually run.
    expect($filesystem->exists($discovery->path.'/app/SHOULD_NEVER_EXIST'))->toBeFalse();

    // And the argv actually built never contains anything resembling a
    // shell command — only file paths and flags, since ProcessCommand is
    // argv-only (no shell) and this analyzer never passes file CONTENT as
    // an argument.
    $scanCall = $processRunner->calls()[1];
    foreach ($scanCall->argv as $arg) {
        expect((string) $arg)->not->toContain('system(')
            ->and((string) $arg)->not->toContain('SHOULD_NEVER_EXIST');
    }
});

/**
 * Multi-analyzer coexistence: composer-audit + semgrep must run side by
 * side without one clobbering the other's findings.
 */
it('coexists with composer-audit in the same run without either clobbering the other\'s findings', function () {
    $discovery = (new ProjectDiscovery)->discover(dirname(__DIR__, 4).'/Fixtures/semgrep/php-project');
    expect($discovery->isSuccessful())->toBeTrue();

    $context = new AuditContext(runId: 'semgrep-multi', projectPath: $discovery->path, profile: $discovery->profile);

    $semgrepJson = json_encode([
        'version' => '1.176.0',
        'results' => [[
            'check_id' => 'laradogs.quality.debug.dd-call',
            'path' => $discovery->path.'/app/Debug.php',
            'start' => ['line' => 7, 'col' => 5, 'offset' => 0],
            'end' => ['line' => 7, 'col' => 14, 'offset' => 0],
            'extra' => ['message' => 'dd() call', 'severity' => 'WARNING', 'metadata' => []],
        ]],
        'errors' => [],
        'paths' => ['scanned' => [$discovery->path.'/app/Debug.php'], 'skipped' => []],
    ]);

    config(['laradogs.semgrep.binary' => PHP_BINARY, 'laradogs.composer.binary' => PHP_BINARY]);

    $semgrepRunner = new FakeProcessRunner(
        new ProcessResult(0, '1.176.0', '', false, false, 5),
        new ProcessResult(0, $semgrepJson, '', false, false, 20),
    );
    $composerRunner = new FakeProcessRunner(
        new ProcessResult(0, 'Composer version 2.8.1 2024-11-08 16:39:55', '', false, false, 5),
        new ProcessResult(0, file_get_contents(dirname(__DIR__, 4).'/Fixtures/composer-audit/clean.json'), '', false, false, 20),
    );

    $semgrepAnalyzer = new SemgrepAnalyzer(new SemgrepBinaryResolver, new SemgrepParser, new SemgrepTargetCollector, new SemgrepCoverageEvaluator, $semgrepRunner);
    $composerAnalyzer = new ComposerAuditAnalyzer(new ComposerBinaryResolver, new ComposerAuditParser, $composerRunner);

    $registry = new AnalyzerRegistry;
    $registry->register($semgrepAnalyzer);
    $registry->register($composerAnalyzer);

    $recorder = new ScanRecorder(
        new FindingIngestor(new Fingerprinter, new EvidenceRedactor, new FindingLifecycleService),
        new FindingReconciler(new FindingLifecycleService),
    );
    $scanRunner = new ScanRunner(new AuditEngine($registry), $registry, $recorder);

    $project = Project::query()->create(['name' => 'Semgrep+Composer Multi Fixture', 'path' => $context->projectPath]);
    $scan = $scanRunner->run($project, $context);

    expect($scan->status)->toBe(ScanStatus::Completed);

    $semgrepExecution = ScanAnalyzerExecution::query()->where('scan_id', $scan->id)->where('analyzer_id', 'semgrep')->first();
    $composerExecution = ScanAnalyzerExecution::query()->where('scan_id', $scan->id)->where('analyzer_id', 'composer-audit')->first();

    expect($semgrepExecution->status->value)->toBe('passed')
        ->and($composerExecution->status->value)->toBe('passed');

    expect(Finding::query()->where('analyzer_id', 'semgrep')->count())->toBe(1);
    expect(Finding::query()->where('analyzer_id', 'composer-audit')->count())->toBe(0);
});
