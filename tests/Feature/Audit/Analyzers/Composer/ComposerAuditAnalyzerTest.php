<?php

use App\Audit\Analyzers\Composer\ComposerAuditAnalyzer;
use App\Audit\Analyzers\Composer\ComposerAuditParser;
use App\Audit\Analyzers\Composer\ComposerBinaryResolver;
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

function composerAuditFixtureJson(string $name): string
{
    return file_get_contents(dirname(__DIR__, 4).'/Fixtures/composer-audit/'.$name);
}

function composerDiscoveryContext(string $fixture): AuditContext
{
    $discovery = (new ProjectDiscovery)->discover(dirname(__DIR__, 4).'/Fixtures/discovery/'.$fixture);
    expect($discovery->isSuccessful())->toBeTrue();

    return new AuditContext(runId: 'test-run', projectPath: $discovery->path, profile: $discovery->profile);
}

function makeComposerAuditAnalyzer(FakeProcessRunner $runner): ComposerAuditAnalyzer
{
    // A real, executable file so ComposerBinaryResolver's is_file()/
    // is_executable() checks succeed — its actual content is irrelevant
    // since ProcessRunner itself is faked.
    config(['laradogs.composer.binary' => PHP_BINARY]);

    return new ComposerAuditAnalyzer(new ComposerBinaryResolver, new ComposerAuditParser, $runner);
}

function versionCheckResult(string $version = '2.8.1'): ProcessResult
{
    return new ProcessResult(
        exitCode: 0,
        stdout: "Composer version {$version} 2024-11-08 16:39:55",
        stderr: '',
        timedOut: false,
        outputTruncated: false,
        durationMs: 5,
    );
}

// --- Applicability -----------------------------------------------------

it('is applicable to a Composer project with a lock file', function () {
    $analyzer = makeComposerAuditAnalyzer(new FakeProcessRunner);
    $context = composerDiscoveryContext('laravel-with-composer-lock');

    expect($analyzer->applicability($context->profile)->isApplicable())->toBeTrue();
});

it('is not applicable to a Composer project with no lock file, and never suggests installing one', function () {
    $analyzer = makeComposerAuditAnalyzer(new FakeProcessRunner);
    $context = composerDiscoveryContext('laravel-without-composer-lock');

    $applicability = $analyzer->applicability($context->profile);

    expect($applicability->isApplicable())->toBeFalse()
        ->and($applicability->reason)->toContain('lock');
});

it('is not applicable to a non-Composer project', function () {
    $analyzer = makeComposerAuditAnalyzer(new FakeProcessRunner);
    $context = composerDiscoveryContext('node-only');

    expect($analyzer->applicability($context->profile)->isApplicable())->toBeFalse();
});

// --- Availability --------------------------------------------------------

it('is unavailable when the composer binary cannot be resolved', function () {
    config(['laradogs.composer.binary' => '/no/such/binary/here']);
    $analyzer = new ComposerAuditAnalyzer(new ComposerBinaryResolver, new ComposerAuditParser, new FakeProcessRunner);
    $context = composerDiscoveryContext('laravel-with-composer-lock');

    expect($analyzer->availability($context)->isAvailable())->toBeFalse();
});

it('is unavailable when the version check process fails', function () {
    $runner = new FakeProcessRunner(new ProcessResult(
        exitCode: 1,
        stdout: '',
        stderr: 'something went wrong',
        timedOut: false,
        outputTruncated: false,
        durationMs: 5,
    ));
    $analyzer = makeComposerAuditAnalyzer($runner);
    $context = composerDiscoveryContext('laravel-with-composer-lock');

    expect($analyzer->availability($context)->isAvailable())->toBeFalse();
});

it('is unavailable when the installed Composer version is below the minimum supported', function () {
    $runner = new FakeProcessRunner(versionCheckResult('2.2.0'));
    $analyzer = makeComposerAuditAnalyzer($runner);
    $context = composerDiscoveryContext('laravel-with-composer-lock');

    $availability = $analyzer->availability($context);

    expect($availability->isAvailable())->toBeFalse()
        ->and($availability->reason)->toContain('2.2.0');
});

it('is available when a supported Composer version is detected', function () {
    $runner = new FakeProcessRunner(versionCheckResult('2.8.1'));
    $analyzer = makeComposerAuditAnalyzer($runner);
    $context = composerDiscoveryContext('laravel-with-composer-lock');

    expect($analyzer->availability($context)->isAvailable())->toBeTrue();
});

// --- run(): safety of the invoked command ---------------------------------

it('invokes composer audit with --locked --no-plugins --no-scripts, never mutating the target', function () {
    $runner = new FakeProcessRunner(
        versionCheckResult(),
        new ProcessResult(0, composerAuditFixtureJson('clean.json'), '', false, false, 10),
    );
    $analyzer = makeComposerAuditAnalyzer($runner);
    $context = composerDiscoveryContext('laravel-with-composer-lock');

    expect($analyzer->availability($context)->isAvailable())->toBeTrue();
    $analyzer->run($context);

    $auditCall = $runner->calls()[1];

    expect($auditCall->argv)->toContain('audit')
        ->and($auditCall->argv)->toContain('--locked')
        ->and($auditCall->argv)->toContain('--no-plugins')
        ->and($auditCall->argv)->toContain('--no-scripts')
        ->and($auditCall->argv)->toContain('--format=json')
        ->and($auditCall->workingDirectory)->toBe($context->projectPath);
});

// --- run(): outcome handling ---------------------------------------------

it('passes with zero candidates on a clean audit', function () {
    $runner = new FakeProcessRunner(new ProcessResult(0, composerAuditFixtureJson('clean.json'), '', false, false, 10));
    $analyzer = makeComposerAuditAnalyzer($runner);
    $context = composerDiscoveryContext('laravel-with-composer-lock');

    $result = $analyzer->run($context);

    expect($result->status)->toBe(ExecutionStatus::Passed)
        ->and($analyzer->candidates($context, $result))->toBe([]);
});

it('passes with exit code 1 when advisories are found — a non-zero exit is not itself a failure', function () {
    $runner = new FakeProcessRunner(new ProcessResult(1, composerAuditFixtureJson('with-advisories.json'), '', false, false, 10));
    $analyzer = makeComposerAuditAnalyzer($runner);
    $context = composerDiscoveryContext('laravel-with-composer-lock');

    $result = $analyzer->run($context);

    expect($result->status)->toBe(ExecutionStatus::Passed);

    $candidates = $analyzer->candidates($context, $result);
    expect($candidates)->toHaveCount(2);

    $withSeverity = $candidates[0];
    expect($withSeverity->ruleId)->toBe('vendor/vulnerable-package:PKSA-abcd-1234-efgh')
        ->and($withSeverity->analyzerId)->toBe('composer-audit')
        ->and($withSeverity->severity)->toBe(Severity::High)
        ->and($withSeverity->confidence->value)->toBe('high')
        ->and($withSeverity->cve)->toBe('CVE-2025-00001')
        ->and($withSeverity->references)->toBe(['https://packagist.org/advisories/PKSA-abcd-1234-efgh']);

    $withoutSeverity = $candidates[1];
    expect($withoutSeverity->severity)->toBe(Severity::Unknown);
});

it('never invents a severity for an advisory the source did not rate', function () {
    $runner = new FakeProcessRunner(new ProcessResult(1, composerAuditFixtureJson('with-advisories.json'), '', false, false, 10));
    $analyzer = makeComposerAuditAnalyzer($runner);
    $context = composerDiscoveryContext('laravel-with-composer-lock');

    $result = $analyzer->run($context);
    $candidates = $analyzer->candidates($context, $result);

    expect(collect($candidates)->firstWhere('severity', Severity::Unknown))->not->toBeNull();
});

it('fails on malformed JSON output rather than reporting a false-clean pass', function () {
    $runner = new FakeProcessRunner(new ProcessResult(0, '{not valid json', '', false, false, 10));
    $analyzer = makeComposerAuditAnalyzer($runner);
    $context = composerDiscoveryContext('laravel-with-composer-lock');

    $result = $analyzer->run($context);

    expect($result->status)->toBe(ExecutionStatus::Failed)
        ->and($analyzer->candidates($context, $result))->toBe([]);
});

it('reports timedOut distinctly, never as a plain failure or a pass', function () {
    $runner = new FakeProcessRunner(new ProcessResult(null, '', '', true, false, 30_000));
    $analyzer = makeComposerAuditAnalyzer($runner);
    $context = composerDiscoveryContext('laravel-with-composer-lock');

    expect($analyzer->run($context)->status)->toBe(ExecutionStatus::TimedOut);
});

it('fails on truncated output rather than trusting a partial JSON body', function () {
    $runner = new FakeProcessRunner(new ProcessResult(0, composerAuditFixtureJson('clean.json'), '', false, true, 10));
    $analyzer = makeComposerAuditAnalyzer($runner);
    $context = composerDiscoveryContext('laravel-with-composer-lock');

    expect($analyzer->run($context)->status)->toBe(ExecutionStatus::Failed);
});

it('fails closed on unreachable advisory repositories rather than reporting a false-clean scan', function () {
    $runner = new FakeProcessRunner(new ProcessResult(0, composerAuditFixtureJson('with-unreachable-repositories.json'), '', false, false, 10));
    $analyzer = makeComposerAuditAnalyzer($runner);
    $context = composerDiscoveryContext('laravel-with-composer-lock');

    $result = $analyzer->run($context);

    expect($result->status)->toBe(ExecutionStatus::Failed)
        ->and($result->rawMetadata['unreachable_repositories'])->toBe(['https://packagist.org'])
        ->and($analyzer->candidates($context, $result))->toBe([]);
});

it('reports abandoned packages as an informational diagnostic, never as a Finding candidate', function () {
    $runner = new FakeProcessRunner(new ProcessResult(0, composerAuditFixtureJson('with-abandoned.json'), '', false, false, 10));
    $analyzer = makeComposerAuditAnalyzer($runner);
    $context = composerDiscoveryContext('laravel-with-composer-lock');

    $result = $analyzer->run($context);

    expect($result->status)->toBe(ExecutionStatus::Passed)
        ->and($result->diagnostics)->toHaveCount(1)
        ->and($result->diagnostics[0]->level->value)->toBe('info')
        ->and($analyzer->candidates($context, $result))->toBe([]);
});

it('always declares Unknown coverage — Composer gives no explicit rule-id universe to declare Explicit/Full from', function () {
    $runner = new FakeProcessRunner(new ProcessResult(1, composerAuditFixtureJson('with-advisories.json'), '', false, false, 10));
    $analyzer = makeComposerAuditAnalyzer($runner);
    $context = composerDiscoveryContext('laravel-with-composer-lock');

    expect($analyzer->run($context)->coverage->mode)->toBe(CoverageMode::Unknown);
});

// --- Phase 4.1: Docker deployment readiness (env allowlist forwarding) ---

it('forwards COMPOSER_HOME through the environment allowlist when LaraDogs itself has it set', function () {
    // Mirrors the Docker runtime image (Phase 4.1), which sets
    // COMPOSER_HOME=/home/laradogs/.composer as a container-level env var
    // — this proves that value reaches the composer subprocess through
    // the existing allowlist mechanism with zero analyzer code changes,
    // since COMPOSER_HOME is already in config('laradogs.process.env_allowlist').
    putenv('COMPOSER_HOME=/home/laradogs/.composer');

    try {
        $runner = new FakeProcessRunner(
            versionCheckResult(),
            new ProcessResult(0, composerAuditFixtureJson('clean.json'), '', false, false, 10),
        );
        $analyzer = makeComposerAuditAnalyzer($runner);
        $context = composerDiscoveryContext('laravel-with-composer-lock');

        expect($analyzer->availability($context)->isAvailable())->toBeTrue();
        $analyzer->run($context);

        $auditCall = $runner->calls()[1];

        expect($auditCall->environment)->toHaveKey('COMPOSER_HOME', '/home/laradogs/.composer');
    } finally {
        putenv('COMPOSER_HOME');
    }
});

it('never requires write access to its own working directory — the target may be mounted read-only', function () {
    // A weaker, portable (non-Docker) proxy for Docker's read-only target
    // mount: a filesystem-level read-only directory. The real, decisive
    // proof against a REAL composer binary lives in
    // ComposerAuditRealBinaryTest.php (opt-in, network-gated) — this test
    // only proves ComposerAuditAnalyzer itself never tries to write into
    // AuditContext::$projectPath (it only ever reads argv/cwd into a
    // ProcessCommand, never touches the filesystem directly).
    $readOnlyDir = sys_get_temp_dir().'/laradogs-readonly-target-'.bin2hex(random_bytes(8));
    mkdir($readOnlyDir);
    file_put_contents($readOnlyDir.'/composer.json', '{"require":{"php":"^8.3"}}');
    file_put_contents($readOnlyDir.'/composer.lock', '{"packages":[],"packages-dev":[]}');
    chmod($readOnlyDir, 0o555);

    try {
        $runner = new FakeProcessRunner(
            versionCheckResult(),
            new ProcessResult(0, composerAuditFixtureJson('clean.json'), '', false, false, 10),
        );
        $analyzer = makeComposerAuditAnalyzer($runner);

        $discovery = (new ProjectDiscovery)->discover($readOnlyDir);
        expect($discovery->isSuccessful())->toBeTrue();

        $context = new AuditContext(runId: 'ro-test', projectPath: $discovery->path, profile: $discovery->profile);

        expect($analyzer->availability($context)->isAvailable())->toBeTrue();
        $result = $analyzer->run($context);

        expect($result->status)->toBe(ExecutionStatus::Passed);
    } finally {
        chmod($readOnlyDir, 0o755);
        (new Filesystem)->deleteDirectory($readOnlyDir);
    }
});
