<?php

use App\Audit\Analyzers\Composer\ComposerAuditAnalyzer;
use App\Audit\Analyzers\Composer\ComposerAuditParser;
use App\Audit\Analyzers\Composer\ComposerBinaryResolver;
use App\Audit\Discovery\ProjectDiscovery;
use App\Audit\Engine\AuditContext;
use App\Audit\Engine\AuditEngine;
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

/**
 * The required full-pipeline test: a synthetic project -> real
 * ProjectDiscovery -> real ComposerAuditAnalyzer -> a fake/captured
 * ProcessRunner result -> real AuditEngine -> real FindingCandidate
 * normalization -> real ScanRunner/ScanRecorder -> real persistence.
 *
 * Reuses the `no-code-execution` Discovery fixture (whose composer.json
 * declares malicious post-install/post-update scripts) copied to a
 * throwaway temp directory with a synthetic composer.lock added, so the
 * analyzer is Applicable — proving, end-to-end, that even a project
 * designed to run code on install never gets that chance through this
 * pipeline.
 */
it('runs the full pipeline end-to-end and persists a Scan, ScanAnalyzerExecution, Finding and FindingOccurrence', function () {
    $filesystem = new Filesystem;
    $source = dirname(__DIR__, 4).'/Fixtures/discovery/no-code-execution';
    $target = sys_get_temp_dir().'/laradogs-composer-e2e-'.uniqid();

    $filesystem->copyDirectory($source, $target);
    file_put_contents($target.'/composer.lock', json_encode([
        'packages' => [],
        'packages-dev' => [],
        'content-hash' => 'irrelevant-for-this-test',
    ]));

    try {
        $discovery = (new ProjectDiscovery)->discover($target);
        expect($discovery->isSuccessful())->toBeTrue();

        config(['laradogs.composer.binary' => PHP_BINARY]);

        $withAdvisoriesJson = file_get_contents(dirname(__DIR__, 4).'/Fixtures/composer-audit/with-advisories.json');

        $processRunner = new FakeProcessRunner(
            new ProcessResult(0, 'Composer version 2.8.1 2024-11-08 16:39:55', '', false, false, 5),
            new ProcessResult(1, $withAdvisoriesJson, '', false, false, 20),
        );

        $analyzer = new ComposerAuditAnalyzer(new ComposerBinaryResolver, new ComposerAuditParser, $processRunner);

        $registry = new AnalyzerRegistry;
        $registry->register($analyzer);

        $context = new AuditContext(runId: 'e2e-run', projectPath: $discovery->path, profile: $discovery->profile);

        $recorder = new ScanRecorder(
            new FindingIngestor(new Fingerprinter, new EvidenceRedactor, new FindingLifecycleService),
            new FindingReconciler(new FindingLifecycleService),
        );
        $scanRunner = new ScanRunner(new AuditEngine($registry), $registry, $recorder);

        $project = Project::query()->create(['name' => 'Composer E2E Fixture', 'path' => $context->projectPath]);

        $scan = $scanRunner->run($project, $context);

        expect($scan->status)->toBe(ScanStatus::Completed);

        $execution = ScanAnalyzerExecution::query()->where('scan_id', $scan->id)->where('analyzer_id', 'composer-audit')->first();
        expect($execution)->not->toBeNull()
            ->and($execution->status->value)->toBe('passed');

        $findings = Finding::query()->where('analyzer_id', 'composer-audit')->get();
        expect($findings)->toHaveCount(2);

        $occurrences = FindingOccurrence::query()->where('scan_id', $scan->id)->get();
        expect($occurrences)->toHaveCount(2);

        $withSeverity = $findings->firstWhere('rule_id', 'vendor/vulnerable-package:PKSA-abcd-1234-efgh');
        expect($withSeverity)->not->toBeNull()
            ->and($withSeverity->severity->value)->toBe('high')
            ->and($withSeverity->cve)->toBe('CVE-2025-00001');

        // The exact security property this pipeline exists to guarantee:
        // the target's own post-install-cmd/post-update-cmd scripts never
        // ran, in the temp copy OR the committed fixture.
        expect($filesystem->exists($target.'/SHOULD_NEVER_EXIST'))->toBeFalse();
        expect($filesystem->exists($source.'/SHOULD_NEVER_EXIST'))->toBeFalse();

        // And the actual argv built by the real analyzer, for the real
        // audit call (index 1; index 0 is the --version check), carried
        // the flags that make that guarantee true even under a real
        // composer binary.
        $auditCommand = $processRunner->calls()[1];
        expect($auditCommand->argv)->toContain('--no-plugins')
            ->and($auditCommand->argv)->toContain('--no-scripts')
            ->and($auditCommand->argv)->toContain('--locked');
    } finally {
        $filesystem->deleteDirectory($target);
    }
});
