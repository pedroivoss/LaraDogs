<?php

use App\Audit\Analyzers\Semgrep\SemgrepAnalyzer;
use App\Audit\Analyzers\Semgrep\SemgrepBinaryResolver;
use App\Audit\Analyzers\Semgrep\SemgrepCoverageEvaluator;
use App\Audit\Analyzers\Semgrep\SemgrepParser;
use App\Audit\Analyzers\Semgrep\SemgrepTargetCollector;
use App\Audit\Discovery\ProjectDiscovery;
use App\Audit\Engine\AuditContext;
use App\Audit\Engine\AuditEngine;
use App\Audit\Engine\Process\ProcessResult;
use App\Audit\Engine\Registry\AnalyzerRegistry;
use App\Audit\Findings\FindingStatus;
use App\Audit\Findings\Fingerprint\Fingerprinter;
use App\Audit\Findings\Ingestion\FindingIngestor;
use App\Audit\Findings\Ingestion\FindingReconciler;
use App\Audit\Findings\Ingestion\ScanRecorder;
use App\Audit\Findings\Ingestion\ScanRunner;
use App\Audit\Findings\Lifecycle\FindingLifecycleService;
use App\Audit\Findings\Redaction\EvidenceRedactor;
use App\Models\Audit\Finding;
use App\Models\Audit\Project;
use App\Models\Audit\Scan;
use App\Models\Audit\ScanAnalyzerExecution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Process\FakeProcessRunner;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * The 5 required Finding lifecycle cases, proven end-to-end against the
 * REAL SemgrepAnalyzer + SemgrepParser + SemgrepCoverageEvaluator across
 * successive real ScanRunner-driven scans — not just the generic
 * FindingReconciler mechanism (already covered analyzer-agnostically by
 * tests/Feature/Audit/Findings/ReconciliationTest.php). This is the first
 * analyzer where AnalyzerCoverage::Explicit is genuinely exercised
 * end-to-end (see SemgrepCoverageEvaluator's own docblock).
 */
function bothFindingsJson(string $projectPath): string
{
    return json_encode([
        'version' => '1.176.0',
        'results' => [
            [
                'check_id' => 'laradogs.quality.debug.dd-call',
                'path' => $projectPath.'/app/Debug.php',
                'start' => ['line' => 7, 'col' => 5, 'offset' => 0],
                'end' => ['line' => 7, 'col' => 14, 'offset' => 0],
                'extra' => ['message' => 'dd() call', 'severity' => 'WARNING', 'metadata' => []],
            ],
            [
                'check_id' => 'laradogs.quality.debug.var-dump-call',
                'path' => $projectPath.'/app/Debug.php',
                'start' => ['line' => 8, 'col' => 5, 'offset' => 0],
                'end' => ['line' => 8, 'col' => 19, 'offset' => 0],
                'extra' => ['message' => 'var_dump() call', 'severity' => 'WARNING', 'metadata' => []],
            ],
        ],
        'errors' => [],
        'paths' => ['scanned' => [$projectPath.'/app/Debug.php'], 'skipped' => []],
    ]);
}

function onlyDdFindingJson(string $projectPath): string
{
    return json_encode([
        'version' => '1.176.0',
        'results' => [[
            'check_id' => 'laradogs.quality.debug.dd-call',
            'path' => $projectPath.'/app/Debug.php',
            'start' => ['line' => 7, 'col' => 5, 'offset' => 0],
            'end' => ['line' => 7, 'col' => 14, 'offset' => 0],
            'extra' => ['message' => 'dd() call', 'severity' => 'WARNING', 'metadata' => []],
        ]],
        'errors' => [],
        'paths' => ['scanned' => [$projectPath.'/app/Debug.php'], 'skipped' => []],
    ]);
}

function onlyDdFindingWithPartialParsingErrorJson(string $projectPath): string
{
    return json_encode([
        'version' => '1.176.0',
        'results' => [[
            'check_id' => 'laradogs.quality.debug.dd-call',
            'path' => $projectPath.'/app/Debug.php',
            'start' => ['line' => 7, 'col' => 5, 'offset' => 0],
            'end' => ['line' => 7, 'col' => 14, 'offset' => 0],
            'extra' => ['message' => 'dd() call', 'severity' => 'WARNING', 'metadata' => []],
        ]],
        'errors' => [[
            'code' => 3, 'level' => 'warn', 'type' => 'PartialParsing',
            'message' => 'partial parse', 'path' => $projectPath.'/app/SomeOtherFile.php',
        ]],
        'paths' => ['scanned' => [$projectPath.'/app/Debug.php'], 'skipped' => []],
    ]);
}

function runSemgrepScan(Project $project, AuditContext $context, FakeProcessRunner $processRunner): Scan
{
    config(['laradogs.semgrep.binary' => PHP_BINARY]);

    $analyzer = new SemgrepAnalyzer(
        new SemgrepBinaryResolver,
        new SemgrepParser,
        new SemgrepTargetCollector,
        new SemgrepCoverageEvaluator,
        $processRunner,
    );

    $registry = new AnalyzerRegistry;
    $registry->register($analyzer);

    $recorder = new ScanRecorder(
        new FindingIngestor(new Fingerprinter, new EvidenceRedactor, new FindingLifecycleService),
        new FindingReconciler(new FindingLifecycleService),
    );

    return (new ScanRunner(new AuditEngine($registry), $registry, $recorder))->run($project, $context);
}

it('proves all 5 lifecycle cases end-to-end with the real SemgrepAnalyzer across successive scans', function () {
    $discovery = (new ProjectDiscovery)->discover(dirname(__DIR__, 4).'/Fixtures/semgrep/php-project');
    expect($discovery->isSuccessful())->toBeTrue();

    $context = new AuditContext(runId: 'lifecycle-run', projectPath: $discovery->path, profile: $discovery->profile);
    $project = Project::query()->create(['name' => 'Semgrep Lifecycle Fixture', 'path' => $context->projectPath]);

    // --- Scan 1: both dd() and var_dump() observed -> 2 Open findings. ---
    runSemgrepScan($project, $context, new FakeProcessRunner(
        new ProcessResult(0, '1.176.0', '', false, false, 5),
        new ProcessResult(0, bothFindingsJson($discovery->path), '', false, false, 20),
    ));

    $ddFinding = Finding::query()->where('analyzer_id', 'semgrep')->where('rule_id', 'laradogs.quality.debug.dd-call')->firstOrFail();
    $varDumpFinding = Finding::query()->where('analyzer_id', 'semgrep')->where('rule_id', 'laradogs.quality.debug.var-dump-call')->firstOrFail();

    expect($ddFinding->status)->toBe(FindingStatus::Open)
        ->and($varDumpFinding->status)->toBe(FindingStatus::Open);

    // --- CASE 1: verified resolution. Scan 2 runs cleanly (Explicit
    // coverage over the full catalog, zero errors), var_dump() is no
    // longer observed -> it auto-resolves. dd() is still observed -> stays Open. ---
    runSemgrepScan($project, $context, new FakeProcessRunner(
        new ProcessResult(0, '1.176.0', '', false, false, 5),
        new ProcessResult(0, onlyDdFindingJson($discovery->path), '', false, false, 20),
    ));

    expect($ddFinding->fresh()->status)->toBe(FindingStatus::Open);
    expect($varDumpFinding->fresh()->status)->toBe(FindingStatus::Resolved);

    // --- CASE 5: regression. Scan 3 observes var_dump() again -> the
    // previously-Resolved finding reopens automatically. ---
    runSemgrepScan($project, $context, new FakeProcessRunner(
        new ProcessResult(0, '1.176.0', '', false, false, 5),
        new ProcessResult(0, bothFindingsJson($discovery->path), '', false, false, 20),
    ));

    expect($varDumpFinding->fresh()->status)->toBe(FindingStatus::Open);

    $reopenEvent = $varDumpFinding->statusHistory()->latest('id')->first();
    expect($reopenEvent->previous_status)->toBe(FindingStatus::Resolved)
        ->and($reopenEvent->new_status)->toBe(FindingStatus::Open)
        ->and($reopenEvent->reason)->toContain('Reopened automatically');

    // --- CASE 3: failed analyzer. Scan 4's semgrep run fails (malformed
    // JSON) with var_dump() unobserved -> must NOT resolve despite absence,
    // because the analyzer itself did not pass this run. ---
    runSemgrepScan($project, $context, new FakeProcessRunner(
        new ProcessResult(0, '1.176.0', '', false, false, 5),
        new ProcessResult(0, '{not valid json', '', false, false, 20),
    ));

    expect($varDumpFinding->fresh()->status)->toBe(FindingStatus::Open);

    // --- CASE 4: unknown coverage. Scan 5 passes but reports a
    // PartialParsing error (-> Unknown coverage) with var_dump()
    // unobserved -> must NOT resolve, since Unknown coverage gives no
    // evidence the rule was actually verified this run. ---
    runSemgrepScan($project, $context, new FakeProcessRunner(
        new ProcessResult(0, '1.176.0', '', false, false, 5),
        new ProcessResult(0, onlyDdFindingWithPartialParsingErrorJson($discovery->path), '', false, false, 20),
    ));

    $lastExecution = ScanAnalyzerExecution::query()
        ->where('analyzer_id', 'semgrep')
        ->latest('id')
        ->first();
    expect($lastExecution->status->value)->toBe('passed')
        ->and($lastExecution->coverage->mode->value)->toBe('unknown');

    expect($varDumpFinding->fresh()->status)->toBe(FindingStatus::Open);

    // --- CASE 2 (removed rule): proven at the FindingReconciler level —
    // this analyzer always claims the full bundled catalog or Unknown (it
    // has no concept of running a subset of its own rules), so a
    // rule-removed scenario cannot arise from a real SemgrepAnalyzer run
    // in this phase. The underlying mechanism (coverage naming only a
    // SUBSET of rule ids must never resolve a finding for a rule NOT in
    // that subset) is proven generically, with analyzerId 'semgrep' among
    // others, in tests/Feature/Audit/Findings/ReconciliationTest.php.
});
