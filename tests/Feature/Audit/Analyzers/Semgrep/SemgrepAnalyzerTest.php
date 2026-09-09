<?php

use App\Audit\Analyzers\Semgrep\SemgrepAnalyzer;
use App\Audit\Analyzers\Semgrep\SemgrepBinaryResolver;
use App\Audit\Analyzers\Semgrep\SemgrepCoverageEvaluator;
use App\Audit\Analyzers\Semgrep\SemgrepParser;
use App\Audit\Analyzers\Semgrep\SemgrepRuleCatalog;
use App\Audit\Analyzers\Semgrep\SemgrepTargetCollector;
use App\Audit\Discovery\ProjectDiscovery;
use App\Audit\Engine\AuditContext;
use App\Audit\Engine\Execution\CoverageMode;
use App\Audit\Engine\Execution\ExecutionStatus;
use App\Audit\Engine\Process\ProcessResult;
use App\Audit\Findings\Severity;
use Illuminate\Filesystem\Filesystem;
use Tests\Support\Process\FakeProcessRunner;
use Tests\TestCase;

uses(TestCase::class);

function semgrepFixtureProjectContext(string $fixture): AuditContext
{
    $discovery = (new ProjectDiscovery)->discover(dirname(__DIR__, 4).'/Fixtures/semgrep/'.$fixture);
    expect($discovery->isSuccessful())->toBeTrue();

    return new AuditContext(runId: 'test-run', projectPath: $discovery->path, profile: $discovery->profile);
}

function makeSemgrepAnalyzer(FakeProcessRunner $runner): SemgrepAnalyzer
{
    // A real, executable file so SemgrepBinaryResolver's is_file()/
    // is_executable() checks succeed — its actual content is irrelevant
    // since ProcessRunner itself is faked.
    config(['laradogs.semgrep.binary' => PHP_BINARY]);

    return new SemgrepAnalyzer(
        new SemgrepBinaryResolver,
        new SemgrepParser,
        new SemgrepTargetCollector,
        new SemgrepCoverageEvaluator,
        $runner,
    );
}

function semgrepVersionCheckResult(string $version = '1.176.0'): ProcessResult
{
    return new ProcessResult(exitCode: 0, stdout: $version, stderr: '', timedOut: false, outputTruncated: false, durationMs: 5);
}

function semgrepScanResult(string $json, int $exitCode = 0): ProcessResult
{
    return new ProcessResult(exitCode: $exitCode, stdout: $json, stderr: '', timedOut: false, outputTruncated: false, durationMs: 10);
}

function semgrepFindingsJson(): string
{
    return json_encode([
        'version' => '1.176.0',
        'results' => [
            [
                'check_id' => 'laradogs.quality.debug.dd-call',
                'path' => '__PROJECT_PATH__/app/Debug.php',
                'start' => ['line' => 7, 'col' => 5, 'offset' => 0],
                'end' => ['line' => 7, 'col' => 14, 'offset' => 0],
                'extra' => ['message' => "dd() halts execution.\nRemove before shipping.", 'severity' => 'WARNING', 'metadata' => []],
            ],
            [
                'check_id' => 'laradogs.quality.debug.var-dump-call',
                'path' => '__PROJECT_PATH__/app/Debug.php',
                'start' => ['line' => 8, 'col' => 5, 'offset' => 0],
                'end' => ['line' => 8, 'col' => 19, 'offset' => 0],
                'extra' => ['message' => 'var_dump() left in code.', 'severity' => 'WARNING', 'metadata' => []],
            ],
        ],
        'errors' => [],
        'paths' => ['scanned' => ['__PROJECT_PATH__/app/Debug.php'], 'skipped' => []],
    ]);
}

// --- Applicability -----------------------------------------------------

it('is applicable to a project with PHP detected', function () {
    $analyzer = makeSemgrepAnalyzer(new FakeProcessRunner);
    $context = semgrepFixtureProjectContext('php-project');

    expect($analyzer->applicability($context->profile)->isApplicable())->toBeTrue();
});

it('is not applicable to a project with no PHP detected', function () {
    $discovery = (new ProjectDiscovery)->discover(dirname(__DIR__, 4).'/Fixtures/discovery/node-only');
    expect($discovery->isSuccessful())->toBeTrue();

    $analyzer = makeSemgrepAnalyzer(new FakeProcessRunner);

    expect($analyzer->applicability($discovery->profile)->isApplicable())->toBeFalse();
});

// --- Availability --------------------------------------------------------

it('is unavailable when the semgrep binary cannot be resolved', function () {
    config(['laradogs.semgrep.binary' => '/no/such/binary/here']);
    $analyzer = new SemgrepAnalyzer(new SemgrepBinaryResolver, new SemgrepParser, new SemgrepTargetCollector, new SemgrepCoverageEvaluator, new FakeProcessRunner);
    $context = semgrepFixtureProjectContext('php-project');

    expect($analyzer->availability($context)->isAvailable())->toBeFalse();
});

it('is unavailable when the version check process fails', function () {
    $runner = new FakeProcessRunner(new ProcessResult(1, '', 'boom', false, false, 5));
    $analyzer = makeSemgrepAnalyzer($runner);
    $context = semgrepFixtureProjectContext('php-project');

    expect($analyzer->availability($context)->isAvailable())->toBeFalse();
});

it('is unavailable when the installed semgrep version is below the minimum supported', function () {
    $runner = new FakeProcessRunner(semgrepVersionCheckResult('1.0.0'));
    $analyzer = makeSemgrepAnalyzer($runner);
    $context = semgrepFixtureProjectContext('php-project');

    $availability = $analyzer->availability($context);

    expect($availability->isAvailable())->toBeFalse()
        ->and($availability->reason)->toContain('1.0.0');
});

it('is available when a supported semgrep version is detected', function () {
    $runner = new FakeProcessRunner(semgrepVersionCheckResult());
    $analyzer = makeSemgrepAnalyzer($runner);
    $context = semgrepFixtureProjectContext('php-project');

    expect($analyzer->availability($context)->isAvailable())->toBeTrue();
});

// --- run(): safety of the invoked command ---------------------------------

it('invokes semgrep scan with --config, --json, --verbose, --metrics=off and an explicit file list — never a directory target', function () {
    $context = semgrepFixtureProjectContext('php-project');
    $json = str_replace('__PROJECT_PATH__', $context->projectPath, semgrepFindingsJson());

    $runner = new FakeProcessRunner(semgrepVersionCheckResult(), semgrepScanResult($json));
    $analyzer = makeSemgrepAnalyzer($runner);

    expect($analyzer->availability($context)->isAvailable())->toBeTrue();
    $analyzer->run($context);

    $scanCall = $runner->calls()[1];

    expect($scanCall->argv)->toContain('scan')
        ->and($scanCall->argv)->toContain('--config')
        ->and($scanCall->argv)->toContain('--json')
        ->and($scanCall->argv)->toContain('--verbose')
        ->and($scanCall->argv)->toContain('--metrics=off')
        ->and($scanCall->argv)->toContain('--oss-only');

    // Every target argv element after the flags is an explicit, absolute
    // .php file path collected by SemgrepTargetCollector — never a bare
    // directory, and never the project path itself as a scan root.
    $targetArgs = array_values(array_filter($scanCall->argv, fn ($arg) => str_ends_with((string) $arg, '.php')));
    expect($targetArgs)->not->toBeEmpty();
    foreach ($targetArgs as $target) {
        expect($target)->not->toBe($context->projectPath)
            ->and(is_file($target))->toBeTrue();
    }

    // cwd is the bundled rules directory, not the target project path —
    // see SemgrepParser's docblock on why (a clean, unprefixed check_id).
    expect($scanCall->workingDirectory)->toBe(dirname(SemgrepRuleCatalog::rulesFilePath()));
});

it('never invokes semgrep with the target project directory itself as a scan argument', function () {
    $context = semgrepFixtureProjectContext('php-project');
    $json = str_replace('__PROJECT_PATH__', $context->projectPath, semgrepFindingsJson());

    $runner = new FakeProcessRunner(semgrepVersionCheckResult(), semgrepScanResult($json));
    $analyzer = makeSemgrepAnalyzer($runner);
    $analyzer->availability($context);
    $analyzer->run($context);

    $scanCall = $runner->calls()[1];
    expect($scanCall->argv)->not->toContain($context->projectPath);
});

it('always forces SEMGREP_SETTINGS_FILE and SEMGREP_SEND_METRICS=off, and never forwards SEMGREP_APP_TOKEN', function () {
    $context = semgrepFixtureProjectContext('php-project');
    $json = str_replace('__PROJECT_PATH__', $context->projectPath, semgrepFindingsJson());

    $runner = new FakeProcessRunner(semgrepVersionCheckResult(), semgrepScanResult($json));
    $analyzer = makeSemgrepAnalyzer($runner);
    $analyzer->availability($context);
    $analyzer->run($context);

    foreach ($runner->calls() as $call) {
        expect($call->environment)->toHaveKey('SEMGREP_SETTINGS_FILE')
            ->and($call->environment['SEMGREP_SETTINGS_FILE'])->toBe((string) config('laradogs.semgrep.settings_path'))
            ->and($call->environment)->toHaveKey('SEMGREP_SEND_METRICS')
            ->and($call->environment['SEMGREP_SEND_METRICS'])->toBe('off')
            ->and($call->environment)->not->toHaveKey('SEMGREP_APP_TOKEN');
    }
});

it('excludes vendor/ from the collected target files, so a finding inside it is never reported', function () {
    $context = semgrepFixtureProjectContext('php-project');
    $json = str_replace('__PROJECT_PATH__', $context->projectPath, semgrepFindingsJson());

    $runner = new FakeProcessRunner(semgrepVersionCheckResult(), semgrepScanResult($json));
    $analyzer = makeSemgrepAnalyzer($runner);
    $analyzer->availability($context);
    $analyzer->run($context);

    $scanCall = $runner->calls()[1];

    foreach ($scanCall->argv as $arg) {
        expect((string) $arg)->not->toContain('/vendor/');
    }
});

// --- run(): outcome handling ---------------------------------------------

it('passes with zero candidates on a clean scan and Explicit coverage', function () {
    $context = semgrepFixtureProjectContext('clean-project');
    $clean = json_encode([
        'version' => '1.176.0',
        'results' => [],
        'errors' => [],
        'paths' => ['scanned' => [$context->projectPath.'/app/Greeter.php'], 'skipped' => []],
    ]);

    $runner = new FakeProcessRunner(semgrepVersionCheckResult(), semgrepScanResult($clean));
    $analyzer = makeSemgrepAnalyzer($runner);
    $analyzer->availability($context);

    $result = $analyzer->run($context);

    expect($result->status)->toBe(ExecutionStatus::Passed)
        ->and($result->coverage->mode)->toBe(CoverageMode::Explicit)
        ->and($analyzer->candidates($context, $result))->toBe([]);
});

it('passes with Explicit coverage over an empty file set when there are no PHP files to scan at all', function () {
    $filesystem = new Filesystem;
    $target = sys_get_temp_dir().'/laradogs-semgrep-empty-'.bin2hex(random_bytes(8));
    $filesystem->makeDirectory($target, recursive: true);
    file_put_contents("$target/composer.json", json_encode(['name' => 'fixture/empty', 'require' => ['php' => '^8.2']]));
    file_put_contents("$target/composer.lock", json_encode(['packages' => [], 'packages-dev' => []]));

    try {
        $discovery = (new ProjectDiscovery)->discover($target);
        expect($discovery->isSuccessful())->toBeTrue();
        $context = new AuditContext(runId: 'empty-test', projectPath: $discovery->path, profile: $discovery->profile);

        $runner = new FakeProcessRunner;
        $analyzer = makeSemgrepAnalyzer($runner);

        $result = $analyzer->run($context);

        expect($result->status)->toBe(ExecutionStatus::Passed)
            ->and($result->coverage->mode)->toBe(CoverageMode::Explicit)
            ->and($runner->calls())->toBe([]); // semgrep never even invoked
    } finally {
        $filesystem->deleteDirectory($target);
    }
});

it('produces one candidate per real finding, with severity mapped, category/confidence from the catalog', function () {
    $context = semgrepFixtureProjectContext('php-project');
    $json = str_replace('__PROJECT_PATH__', $context->projectPath, semgrepFindingsJson());

    $runner = new FakeProcessRunner(semgrepVersionCheckResult(), semgrepScanResult($json));
    $analyzer = makeSemgrepAnalyzer($runner);
    $analyzer->availability($context);

    $result = $analyzer->run($context);
    $candidates = $analyzer->candidates($context, $result);

    expect($candidates)->toHaveCount(2);

    $dd = $candidates[0];
    expect($dd->ruleId)->toBe('laradogs.quality.debug.dd-call')
        ->and($dd->analyzerId)->toBe('semgrep')
        ->and($dd->category->value)->toBe('quality')
        ->and($dd->severity)->toBe(Severity::Medium)
        ->and($dd->confidence->value)->toBe('high')
        ->and($dd->filePath)->toBe('app/Debug.php')
        ->and($dd->lineStart)->toBe(7)
        ->and($dd->lineEnd)->toBe(7)
        ->and($dd->codeSnippet)->toContain('dd(');
});

it('maps CWE/references from Semgrep-native rule metadata onto the candidate', function () {
    $context = semgrepFixtureProjectContext('ignore-bypass-project');
    $json = json_encode([
        'version' => '1.176.0',
        'results' => [[
            'check_id' => 'laradogs.security.php.eval-usage',
            'path' => $context->projectPath.'/app/Vulnerable.php',
            'start' => ['line' => 7, 'col' => 5, 'offset' => 0],
            'end' => ['line' => 7, 'col' => 18, 'offset' => 0],
            'extra' => [
                'message' => 'eval() executes a string as PHP code.',
                'severity' => 'ERROR',
                'metadata' => [
                    'cwe' => ["CWE-95: Improper Neutralization of Directives in Dynamically Evaluated Code ('Eval Injection')"],
                    'references' => ['https://cwe.mitre.org/data/definitions/95.html'],
                ],
            ],
        ]],
        'errors' => [],
        'paths' => ['scanned' => [$context->projectPath.'/app/Vulnerable.php'], 'skipped' => []],
    ]);

    $runner = new FakeProcessRunner(semgrepVersionCheckResult(), semgrepScanResult($json));
    $analyzer = makeSemgrepAnalyzer($runner);
    $analyzer->availability($context);

    $result = $analyzer->run($context);
    $candidates = $analyzer->candidates($context, $result);

    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]->severity)->toBe(Severity::High)
        ->and($candidates[0]->cwe)->toBe("CWE-95: Improper Neutralization of Directives in Dynamically Evaluated Code ('Eval Injection')")
        ->and($candidates[0]->references)->toBe(['https://cwe.mitre.org/data/definitions/95.html']);
});

it('downgrades to Unknown coverage when the run reported any error, even alongside real findings', function () {
    $context = semgrepFixtureProjectContext('php-project');
    $withError = json_encode([
        'version' => '1.176.0',
        'results' => [[
            'check_id' => 'laradogs.quality.debug.dd-call',
            'path' => $context->projectPath.'/app/Debug.php',
            'start' => ['line' => 7, 'col' => 5, 'offset' => 0],
            'end' => ['line' => 7, 'col' => 14, 'offset' => 0],
            'extra' => ['message' => 'dd() call', 'severity' => 'WARNING', 'metadata' => []],
        ]],
        'errors' => [[
            'code' => 3, 'level' => 'warn', 'type' => 'PartialParsing',
            'message' => 'partial parse', 'path' => $context->projectPath.'/app/Other.php',
        ]],
        'paths' => ['scanned' => [$context->projectPath.'/app/Debug.php'], 'skipped' => []],
    ]);

    $runner = new FakeProcessRunner(semgrepVersionCheckResult(), semgrepScanResult($withError));
    $analyzer = makeSemgrepAnalyzer($runner);
    $analyzer->availability($context);

    $result = $analyzer->run($context);

    expect($result->status)->toBe(ExecutionStatus::Passed)
        ->and($result->coverage->mode)->toBe(CoverageMode::Unknown)
        ->and($analyzer->candidates($context, $result))->toHaveCount(1);
});

it('fails closed (not merely Unknown coverage) on a non-zero exit code — a config/rule failure, never "findings found"', function () {
    $context = semgrepFixtureProjectContext('php-project');
    $invalidRuleJson = json_encode([
        'version' => '1.176.0',
        'results' => [],
        'errors' => [
            ['code' => 5, 'level' => 'error', 'type' => 'InvalidYaml', 'message' => 'bad yaml'],
            ['code' => 7, 'level' => 'error', 'type' => 'SemgrepError', 'message' => 'invalid configuration file found'],
        ],
        'paths' => ['scanned' => [], 'skipped' => []],
    ]);

    $runner = new FakeProcessRunner(semgrepVersionCheckResult(), semgrepScanResult($invalidRuleJson, exitCode: 7));
    $analyzer = makeSemgrepAnalyzer($runner);
    $analyzer->availability($context);

    $result = $analyzer->run($context);

    expect($result->status)->toBe(ExecutionStatus::Failed)
        ->and($analyzer->candidates($context, $result))->toBe([]);
});

it('fails closed on malformed JSON output rather than reporting a false-clean pass', function () {
    $context = semgrepFixtureProjectContext('php-project');
    $runner = new FakeProcessRunner(semgrepVersionCheckResult(), semgrepScanResult('{not valid json'));
    $analyzer = makeSemgrepAnalyzer($runner);
    $analyzer->availability($context);

    $result = $analyzer->run($context);

    expect($result->status)->toBe(ExecutionStatus::Failed)
        ->and($analyzer->candidates($context, $result))->toBe([]);
});

it('reports timedOut distinctly, never as a plain failure or a pass, with Unknown coverage and an actionable message', function () {
    $context = semgrepFixtureProjectContext('php-project');
    $runner = new FakeProcessRunner(semgrepVersionCheckResult(), new ProcessResult(null, '', '', true, false, 60_000));
    $analyzer = makeSemgrepAnalyzer($runner);
    $analyzer->availability($context);

    $result = $analyzer->run($context);

    expect($result->status)->toBe(ExecutionStatus::TimedOut)
        // Fail-closed: a timed-out run must never claim it verified any
        // rule — see SemgrepCoverageEvaluator and ADR-0010's amendment.
        ->and($result->coverage->mode)->toBe(CoverageMode::Unknown)
        ->and($analyzer->candidates($context, $result))->toBe([])
        // Actionable per Phase 6's real-world validation (allimaPanel):
        // the message must point at the actual config knob, not just say
        // "timed out."
        ->and($result->summary)->toContain('LARADOGS_SEMGREP_TIMEOUT_SECONDS')
        ->and($result->summary)->toContain('Unknown');
});

it('honors a configured whole-process timeout (LaraDogs config, never the target) rather than a hardcoded value', function () {
    // A real, measured default informed by Phase 6's real-world validation
    // (see config/laradogs.php's own docblock) — asserting the ACTUAL
    // configured value flows through, not a hardcoded literal, so a large
    // real project can be accommodated via config alone.
    config(['laradogs.semgrep.timeout_seconds' => 1200]);

    $context = semgrepFixtureProjectContext('php-project');
    $json = str_replace('__PROJECT_PATH__', $context->projectPath, semgrepFindingsJson());
    $runner = new FakeProcessRunner(semgrepVersionCheckResult(), semgrepScanResult($json));
    $analyzer = makeSemgrepAnalyzer($runner);
    $analyzer->availability($context);

    $analyzer->run($context);

    $scanCall = $runner->calls()[1];
    expect($scanCall->timeoutSeconds)->toBe(1200);
});

it('fails on truncated output rather than trusting a partial JSON body', function () {
    $context = semgrepFixtureProjectContext('php-project');
    $json = str_replace('__PROJECT_PATH__', $context->projectPath, semgrepFindingsJson());
    $runner = new FakeProcessRunner(semgrepVersionCheckResult(), new ProcessResult(0, $json, '', false, true, 10));
    $analyzer = makeSemgrepAnalyzer($runner);
    $analyzer->availability($context);

    expect($analyzer->run($context)->status)->toBe(ExecutionStatus::Failed);
});

it('never invents a category for a candidate — always resolved from SemgrepRuleCatalog', function () {
    $context = semgrepFixtureProjectContext('ignore-bypass-project');
    $json = json_encode([
        'version' => '1.176.0',
        'results' => [[
            'check_id' => 'laradogs.security.php.eval-usage',
            'path' => $context->projectPath.'/app/Vulnerable.php',
            'start' => ['line' => 7, 'col' => 5, 'offset' => 0],
            'end' => ['line' => 7, 'col' => 18, 'offset' => 0],
            'extra' => ['message' => 'eval() call', 'severity' => 'ERROR', 'metadata' => []],
        ]],
        'errors' => [],
        'paths' => ['scanned' => [$context->projectPath.'/app/Vulnerable.php'], 'skipped' => []],
    ]);

    $runner = new FakeProcessRunner(semgrepVersionCheckResult(), semgrepScanResult($json));
    $analyzer = makeSemgrepAnalyzer($runner);
    $analyzer->availability($context);

    $result = $analyzer->run($context);
    $candidates = $analyzer->candidates($context, $result);

    expect($candidates[0]->category->value)->toBe('security');
});
