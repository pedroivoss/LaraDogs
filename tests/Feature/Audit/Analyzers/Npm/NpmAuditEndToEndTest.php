<?php

use App\Audit\Analyzers\Npm\NpmAuditAnalyzer;
use App\Audit\Analyzers\Npm\NpmAuditParser;
use App\Audit\Analyzers\Npm\NpmBinaryResolver;
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
 * The required full-pipeline test: a synthetic npm project -> real
 * ProjectDiscovery -> real NpmAuditAnalyzer -> a fake/captured
 * ProcessRunner result -> real AuditEngine -> real FindingCandidate
 * normalization -> real ScanRunner/ScanRecorder -> real persistence.
 *
 * Reuses the `npm-malicious-scripts` fixture (whose package.json declares
 * malicious preinstall/install/postinstall/prepare/prepublish scripts)
 * copied to a throwaway temp directory, proving end-to-end that even a
 * project designed to run code on install never gets that chance through
 * this pipeline. The real-npm-binary proof that these scripts genuinely
 * never execute lives in NpmAuditRealBinaryTest.php (opt-in); this test
 * uses a fake ProcessRunner and focuses on the persistence pipeline.
 */
it('runs the full pipeline end-to-end and persists a Scan, ScanAnalyzerExecution, Finding and FindingOccurrence', function () {
    $filesystem = new Filesystem;
    $source = dirname(__DIR__, 4).'/Fixtures/discovery/npm-malicious-scripts';
    $target = sys_get_temp_dir().'/laradogs-npm-e2e-'.uniqid();

    $filesystem->copyDirectory($source, $target);

    try {
        $discovery = (new ProjectDiscovery)->discover($target);
        expect($discovery->isSuccessful())->toBeTrue();

        config(['laradogs.npm.binary' => PHP_BINARY]);

        $withVulnerabilityJson = file_get_contents(dirname(__DIR__, 4).'/Fixtures/npm-audit/with-direct-vulnerability.json');

        $processRunner = new FakeProcessRunner(
            new ProcessResult(0, '10.9.7', '', false, false, 5),
            new ProcessResult(1, $withVulnerabilityJson, '', false, false, 20),
        );

        $analyzer = new NpmAuditAnalyzer(new NpmBinaryResolver, new NpmAuditParser, $processRunner);

        $registry = new AnalyzerRegistry;
        $registry->register($analyzer);

        $context = new AuditContext(runId: 'npm-e2e-run', projectPath: $discovery->path, profile: $discovery->profile);

        $recorder = new ScanRecorder(
            new FindingIngestor(new Fingerprinter, new EvidenceRedactor, new FindingLifecycleService),
            new FindingReconciler(new FindingLifecycleService),
        );
        $scanRunner = new ScanRunner(new AuditEngine($registry), $registry, $recorder);

        $project = Project::query()->create(['name' => 'Npm E2E Fixture', 'path' => $context->projectPath]);

        $scan = $scanRunner->run($project, $context);

        expect($scan->status)->toBe(ScanStatus::Completed);

        $execution = ScanAnalyzerExecution::query()->where('scan_id', $scan->id)->where('analyzer_id', 'npm-audit')->first();
        expect($execution)->not->toBeNull()
            ->and($execution->status->value)->toBe('passed');

        $findings = Finding::query()->where('analyzer_id', 'npm-audit')->get();
        expect($findings)->toHaveCount(2);

        $occurrences = FindingOccurrence::query()->where('scan_id', $scan->id)->get();
        expect($occurrences)->toHaveCount(2);

        $withSeverity = $findings->firstWhere('rule_id', 'lodash:1106918');
        expect($withSeverity)->not->toBeNull()
            ->and($withSeverity->severity->value)->toBe('critical');

        // The exact security property this pipeline exists to guarantee:
        // the target's own preinstall/install/postinstall/prepare/
        // prepublish scripts never ran.
        expect($filesystem->exists("{$target}/SHOULD_NEVER_EXIST"))->toBeFalse();
        expect($filesystem->exists("{$source}/SHOULD_NEVER_EXIST"))->toBeFalse();
        expect($filesystem->exists("{$target}/node_modules"))->toBeFalse();

        $auditCommand = $processRunner->calls()[1];
        expect($auditCommand->argv)->toContain('--ignore-scripts')
            ->and($auditCommand->argv)->toContain('--package-lock-only');
    } finally {
        $filesystem->deleteDirectory($target);
    }
});
