<?php

use App\Audit\Analyzers\Composer\ComposerAuditAnalyzer;
use App\Audit\Analyzers\Composer\ComposerAuditParser;
use App\Audit\Analyzers\Composer\ComposerBinaryResolver;
use App\Audit\Discovery\ProjectDiscovery;
use App\Audit\Engine\AuditContext;
use App\Audit\Engine\Execution\ExecutionStatus;
use App\Audit\Engine\Process\SymfonyProcessRunner;
use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

uses(TestCase::class);

/**
 * ONE opt-in, real-`composer`-binary test — proves real-world
 * compatibility with the actual `composer audit` output schema, run
 * against a real (but harmless) locked dependency set. Skipped by default:
 * this suite must never require internet access to pass (see the rest of
 * this directory's tests, which all use a fake/scripted ProcessRunner).
 * Run explicitly with:
 *
 *   LARADOGS_TEST_REAL_COMPOSER=1 php artisan test --filter=ComposerAuditRealBinaryTest
 */
it('runs a real composer audit against a real locked dependency set', function () {
    if (getenv('LARADOGS_TEST_REAL_COMPOSER') !== '1') {
        $this->markTestSkipped('Set LARADOGS_TEST_REAL_COMPOSER=1 to run this opt-in, network-dependent test.');
    }

    $discovery = (new ProjectDiscovery)->discover(dirname(__DIR__, 4).'/Fixtures/discovery/laravel-with-composer-lock');
    expect($discovery->isSuccessful())->toBeTrue();

    $context = new AuditContext(runId: 'real-composer-test', projectPath: $discovery->path, profile: $discovery->profile);

    $analyzer = new ComposerAuditAnalyzer(new ComposerBinaryResolver, new ComposerAuditParser, new SymfonyProcessRunner);

    expect($analyzer->availability($context)->isAvailable())->toBeTrue();

    $result = $analyzer->run($context);

    expect($result->status)->toBeIn([ExecutionStatus::Passed, ExecutionStatus::Failed])
        ->and($result->status)->not->toBe(ExecutionStatus::TimedOut);

    if ($result->status === ExecutionStatus::Passed) {
        expect($result->rawMetadata)->toHaveKeys(['advisories', 'abandoned']);
        $analyzer->candidates($context, $result); // must not throw
    }
});

/**
 * Phase 4.1: the decisive, real-binary proof behind the Docker "read-only
 * target mount" and "Composer cache/home stays outside the target"
 * requirements — a real `composer audit --locked` against a target
 * directory made filesystem-read-only, with COMPOSER_HOME pointed at a
 * directory OUTSIDE the target, asserting: the run still succeeds, and
 * the target directory's contents are byte-for-byte unchanged afterward.
 * Same opt-in gate as the test above — never required for the suite to pass.
 */
it('runs a real composer audit against a read-only target with COMPOSER_HOME outside it', function () {
    if (getenv('LARADOGS_TEST_REAL_COMPOSER') !== '1') {
        $this->markTestSkipped('Set LARADOGS_TEST_REAL_COMPOSER=1 to run this opt-in, network-dependent test.');
    }

    $filesystem = new Filesystem;
    $source = dirname(__DIR__, 4).'/Fixtures/discovery/laravel-with-composer-lock';
    $target = sys_get_temp_dir().'/laradogs-composer-readonly-'.bin2hex(random_bytes(8));
    $composerHome = sys_get_temp_dir().'/laradogs-composer-home-'.bin2hex(random_bytes(8));

    $filesystem->copyDirectory($source, $target);
    $filesystem->makeDirectory($composerHome);
    $beforeManifest = collect($filesystem->allFiles($target))
        ->mapWithKeys(fn ($file) => [$file->getRelativePathname() => md5_file($file->getPathname())])
        ->all();

    chmod($target, 0o555);

    try {
        $discovery = (new ProjectDiscovery)->discover($target);
        expect($discovery->isSuccessful())->toBeTrue();

        $context = new AuditContext(runId: 'real-composer-readonly-test', projectPath: $discovery->path, profile: $discovery->profile);

        putenv("COMPOSER_HOME={$composerHome}");

        try {
            $analyzer = new ComposerAuditAnalyzer(new ComposerBinaryResolver, new ComposerAuditParser, new SymfonyProcessRunner);

            expect($analyzer->availability($context)->isAvailable())->toBeTrue();

            $result = $analyzer->run($context);

            expect($result->status)->not->toBe(ExecutionStatus::Failed)
                ->and($result->status)->not->toBe(ExecutionStatus::TimedOut);
        } finally {
            putenv('COMPOSER_HOME');
        }

        $afterManifest = collect($filesystem->allFiles($target))
            ->mapWithKeys(fn ($file) => [$file->getRelativePathname() => md5_file($file->getPathname())])
            ->all();

        expect($afterManifest)->toBe($beforeManifest);
        expect($filesystem->exists("{$composerHome}/cache"))->toBeTrue();
    } finally {
        chmod($target, 0o755);
        $filesystem->deleteDirectory($target);
        $filesystem->deleteDirectory($composerHome);
    }
});
