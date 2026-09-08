<?php

use App\Audit\Analyzers\Semgrep\SemgrepAnalyzer;
use App\Audit\Analyzers\Semgrep\SemgrepBinaryResolver;
use App\Audit\Analyzers\Semgrep\SemgrepCoverageEvaluator;
use App\Audit\Analyzers\Semgrep\SemgrepParser;
use App\Audit\Analyzers\Semgrep\SemgrepTargetCollector;
use App\Audit\Engine\Process\ProcessResult;
use App\Audit\Engine\Registry\AnalyzerRegistry;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Process\FakeProcessRunner;
use Tests\TestCase;

uses(TestCase::class);

function auditCommandFixturePath(string $name): string
{
    return dirname(__DIR__, 2).'/Fixtures/semgrep/'.$name;
}

/**
 * Registers a single SemgrepAnalyzer, backed by a FakeProcessRunner
 * scripted to report one real-shaped finding, as the container's
 * AnalyzerRegistry — so `laradogs:audit` can be exercised without a real
 * semgrep binary or network access.
 */
function bindFakeSemgrepRegistry(string $projectPath): void
{
    config(['laradogs.semgrep.binary' => PHP_BINARY]);

    $scanJson = json_encode([
        'version' => '1.176.0',
        'results' => [[
            'check_id' => 'laradogs.quality.debug.dd-call',
            'path' => $projectPath.'/app/Debug.php',
            'start' => ['line' => 7, 'col' => 5, 'offset' => 0],
            'end' => ['line' => 7, 'col' => 14, 'offset' => 0],
            'extra' => ['message' => 'dd() call', 'severity' => 'WARNING', 'metadata' => ['remediation' => 'Remove the dd() call.']],
        ]],
        'errors' => [],
        'paths' => ['scanned' => [$projectPath.'/app/Debug.php'], 'skipped' => []],
    ]);

    $runner = new FakeProcessRunner(
        new ProcessResult(0, '1.176.0', '', false, false, 5),
        new ProcessResult(0, $scanJson, '', false, false, 20),
    );

    $analyzer = new SemgrepAnalyzer(
        new SemgrepBinaryResolver,
        new SemgrepParser,
        new SemgrepTargetCollector,
        new SemgrepCoverageEvaluator,
        $runner,
    );

    $registry = new AnalyzerRegistry;
    $registry->register($analyzer);

    app()->instance(AnalyzerRegistry::class, $registry);
}

it('renders findings (rule id, severity, file:line, message) for a human reader', function () {
    $projectPath = auditCommandFixturePath('php-project');
    bindFakeSemgrepRegistry($projectPath);

    $this->artisan('laradogs:audit', ['path' => $projectPath])
        ->assertExitCode(0)
        ->expectsOutputToContain('Findings (1)')
        ->expectsOutputToContain('laradogs.quality.debug.dd-call')
        ->expectsOutputToContain('app/Debug.php:7')
        ->expectsOutputToContain('dd() call');
});

it('includes a normalized findings array (with recommendation) in --json output', function () {
    $projectPath = auditCommandFixturePath('php-project');
    bindFakeSemgrepRegistry($projectPath);

    Artisan::call('laradogs:audit', ['path' => $projectPath, '--json' => true]);
    $output = Artisan::output();

    $decoded = json_decode($output, associative: true);

    expect($decoded)->toHaveKey('findings')
        ->and($decoded['findings'])->toHaveCount(1);

    $finding = $decoded['findings'][0];
    expect($finding['rule_id'])->toBe('laradogs.quality.debug.dd-call')
        ->and($finding['analyzer_id'])->toBe('semgrep')
        ->and($finding['file'])->toBe('app/Debug.php')
        ->and($finding['line_start'])->toBe(7)
        ->and($finding['recommendation'])->toBe('Remove the dd() call.');
});

it('prints nothing extra when an analyzer produces zero findings', function () {
    $projectPath = auditCommandFixturePath('clean-project');

    config(['laradogs.semgrep.binary' => PHP_BINARY]);

    $cleanJson = json_encode([
        'version' => '1.176.0',
        'results' => [],
        'errors' => [],
        'paths' => ['scanned' => [$projectPath.'/app/Greeter.php'], 'skipped' => []],
    ]);

    $runner = new FakeProcessRunner(
        new ProcessResult(0, '1.176.0', '', false, false, 5),
        new ProcessResult(0, $cleanJson, '', false, false, 20),
    );

    $registry = new AnalyzerRegistry;
    $registry->register(new SemgrepAnalyzer(
        new SemgrepBinaryResolver,
        new SemgrepParser,
        new SemgrepTargetCollector,
        new SemgrepCoverageEvaluator,
        $runner,
    ));
    app()->instance(AnalyzerRegistry::class, $registry);

    $this->artisan('laradogs:audit', ['path' => $projectPath])
        ->assertExitCode(0)
        ->doesntExpectOutputToContain('Findings');
});
