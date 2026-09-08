<?php

use App\Audit\Analyzers\Semgrep\SemgrepAnalyzer;
use App\Audit\Analyzers\Semgrep\SemgrepBinaryResolver;
use App\Audit\Analyzers\Semgrep\SemgrepCoverageEvaluator;
use App\Audit\Analyzers\Semgrep\SemgrepParser;
use App\Audit\Analyzers\Semgrep\SemgrepTargetCollector;
use App\Audit\Discovery\ProjectDiscovery;
use App\Audit\Engine\AuditContext;
use App\Audit\Engine\Execution\CoverageMode;
use App\Audit\Engine\Execution\ExecutionStatus;
use App\Audit\Engine\Process\SymfonyProcessRunner;
use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Opt-in, real-`semgrep`-binary tests — proves real-world compatibility
 * with the actual bundled ruleset and CLI, using only local rules/fixtures
 * (no network, no Semgrep account/login). Skipped by default: this suite
 * must never require a real semgrep install to pass. Run explicitly with:
 *
 *   LARADOGS_TEST_REAL_SEMGREP=1 php artisan test --filter=SemgrepAuditRealBinaryTest
 */
function skipUnlessRealSemgrep(TestCase $test): void
{
    if (getenv('LARADOGS_TEST_REAL_SEMGREP') !== '1') {
        $test->markTestSkipped('Set LARADOGS_TEST_REAL_SEMGREP=1 to run this opt-in test against the real semgrep binary.');
    }
}

function makeRealSemgrepAnalyzer(): SemgrepAnalyzer
{
    return new SemgrepAnalyzer(
        new SemgrepBinaryResolver,
        new SemgrepParser,
        new SemgrepTargetCollector,
        new SemgrepCoverageEvaluator,
        new SymfonyProcessRunner,
    );
}

it('runs a real semgrep scan against a real fixture project and finds the expected matches', function () {
    skipUnlessRealSemgrep($this);

    $discovery = (new ProjectDiscovery)->discover(dirname(__DIR__, 4).'/Fixtures/semgrep/php-project');
    expect($discovery->isSuccessful())->toBeTrue();

    $context = new AuditContext(runId: 'real-semgrep-test', projectPath: $discovery->path, profile: $discovery->profile);
    $analyzer = makeRealSemgrepAnalyzer();

    expect($analyzer->availability($context)->isAvailable())->toBeTrue();

    $result = $analyzer->run($context);

    expect($result->status)->toBe(ExecutionStatus::Passed)
        ->and($result->coverage->mode)->toBe(CoverageMode::Explicit);

    $candidates = $analyzer->candidates($context, $result);
    $ruleIds = array_map(fn ($c) => $c->ruleId, $candidates);

    expect($ruleIds)->toContain('laradogs.quality.debug.dd-call')
        ->and($ruleIds)->toContain('laradogs.quality.debug.var-dump-call')
        // vendor/Ignored.php's eval() must never be reported — vendor/ is
        // always excluded by SemgrepTargetCollector.
        ->and($ruleIds)->not->toContain('laradogs.security.php.eval-usage');
});

it('bypasses a target\'s own .semgrepignore/.gitignore — the target cannot hide vulnerable code from LaraDogs', function () {
    skipUnlessRealSemgrep($this);

    $discovery = (new ProjectDiscovery)->discover(dirname(__DIR__, 4).'/Fixtures/semgrep/ignore-bypass-project');
    expect($discovery->isSuccessful())->toBeTrue();

    $context = new AuditContext(runId: 'real-semgrep-ignore-bypass', projectPath: $discovery->path, profile: $discovery->profile);
    $analyzer = makeRealSemgrepAnalyzer();

    expect($analyzer->availability($context)->isAvailable())->toBeTrue();

    $result = $analyzer->run($context);
    $candidates = $analyzer->candidates($context, $result);

    $eval = collect($candidates)->firstWhere('ruleId', 'laradogs.security.php.eval-usage');
    expect($eval)->not->toBeNull()
        ->and($eval->filePath)->toBe('app/Vulnerable.php');
});

it('never executes target PHP source with a real semgrep scan — only reads it as data', function () {
    skipUnlessRealSemgrep($this);

    $filesystem = new Filesystem;
    $discovery = (new ProjectDiscovery)->discover(dirname(__DIR__, 4).'/Fixtures/semgrep/malicious-execution-project');
    expect($discovery->isSuccessful())->toBeTrue();

    $markerPath = $discovery->path.'/app/SHOULD_NEVER_EXIST';
    expect($filesystem->exists($markerPath))->toBeFalse();

    $context = new AuditContext(runId: 'real-semgrep-malicious', projectPath: $discovery->path, profile: $discovery->profile);
    $analyzer = makeRealSemgrepAnalyzer();

    expect($analyzer->availability($context)->isAvailable())->toBeTrue();

    $result = $analyzer->run($context);
    $candidates = $analyzer->candidates($context, $result);

    expect(collect($candidates)->pluck('ruleId'))->toContain('laradogs.quality.debug.dd-call');
    expect($filesystem->exists($markerPath))->toBeFalse();

    if ($filesystem->exists($markerPath)) {
        $filesystem->delete($markerPath);
    }
});

/**
 * The decisive, real-binary proof behind "the target is a read-only
 * mount": a real semgrep scan against a target directory made
 * filesystem-read-only, with SEMGREP_SETTINGS_FILE pointed at a path
 * outside it, asserting the run still succeeds and the target's contents
 * are byte-for-byte unchanged afterward.
 */
it('runs a real semgrep scan against a read-only target without needing to write inside it', function () {
    skipUnlessRealSemgrep($this);

    $filesystem = new Filesystem;
    $source = dirname(__DIR__, 4).'/Fixtures/semgrep/php-project';
    $target = sys_get_temp_dir().'/laradogs-semgrep-readonly-'.bin2hex(random_bytes(8));

    $filesystem->copyDirectory($source, $target);
    $beforeManifest = collect($filesystem->allFiles($target))
        ->mapWithKeys(fn ($file) => [$file->getRelativePathname() => md5_file($file->getPathname())])
        ->all();

    chmod($target, 0o555);

    try {
        $discovery = (new ProjectDiscovery)->discover($target);
        expect($discovery->isSuccessful())->toBeTrue();

        $context = new AuditContext(runId: 'real-semgrep-readonly', projectPath: $discovery->path, profile: $discovery->profile);
        $analyzer = makeRealSemgrepAnalyzer();

        expect($analyzer->availability($context)->isAvailable())->toBeTrue();

        $result = $analyzer->run($context);

        expect($result->status)->not->toBe(ExecutionStatus::Failed)
            ->and($result->status)->not->toBe(ExecutionStatus::TimedOut);

        $afterManifest = collect($filesystem->allFiles($target))
            ->mapWithKeys(fn ($file) => [$file->getRelativePathname() => md5_file($file->getPathname())])
            ->all();

        expect($afterManifest)->toBe($beforeManifest);
    } finally {
        chmod($target, 0o755);
        $filesystem->deleteDirectory($target);
    }
});
