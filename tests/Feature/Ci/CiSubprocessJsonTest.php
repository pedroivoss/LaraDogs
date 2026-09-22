<?php

use Symfony\Component\Process\Process;
use Tests\Support\Git\GitFixture;

/*
 * REAL subprocess validation (section 42/43 "10. JSON stdout only" /
 * "12. no ANSI" / "13. no log contamination"): an in-process Artisan::call
 * cannot prove stdout/stderr are genuinely separate FILE DESCRIPTORS — a
 * real `php artisan` process, with stdout and stderr captured into
 * SEPARATE buffers by Symfony Process, can. Against a throwaway SQLite
 * file (never the app's own :memory: test database) so this exercises the
 * real CLI entry point end to end.
 */

beforeEach(function () {
    if (! GitFixture::available()) {
        $this->markTestSkipped('The git binary is not installed.');
    }

    $this->dbFile = sys_get_temp_dir().'/laradogs-ci-subprocess-'.bin2hex(random_bytes(6)).'.sqlite';
    touch($this->dbFile);

    $this->env = [
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $this->dbFile,
        'DB_HOST' => '',
        'DB_PORT' => '',
        'DB_USERNAME' => '',
        'DB_PASSWORD' => '',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'CACHE_STORE' => 'array',
        'GITHUB_ACTIONS' => false, // explicitly false: overrides any inherited value
        'GITHUB_SHA' => false,
        'GITHUB_TOKEN' => false,
        'NO_COLOR' => '1', // belt-and-braces against ANSI, on top of --json's own suppression
    ];

    $base = dirname(__DIR__, 3);
    (new Process(['php', 'artisan', 'migrate', '--force', '--no-interaction'], $base, $this->env))->mustRun();
});

afterEach(function () {
    @unlink($this->dbFile);
    GitFixture::cleanupAll();
});

function ciSubprocess(array $args, array $env): Process
{
    $base = dirname(__DIR__, 3);
    $process = new Process(['php', 'artisan', 'laradogs:ci:audit', ...$args], $base, $env);
    $process->setTimeout(120);
    $process->run();

    return $process;
}

it('emits ONLY a valid JSON envelope on stdout, with everything else on stderr', function () {
    $repo = GitFixture::repository();

    $process = ciSubprocess([$repo->path, '--json'], $this->env);

    $stdout = $process->getOutput();
    $stderr = $process->getErrorOutput();

    $decoded = json_decode(trim($stdout), true);

    expect(json_last_error())->toBe(JSON_ERROR_NONE, 'stdout must be nothing but the JSON envelope: '.$stdout)
        ->and($decoded)->toBeArray()
        ->and($decoded)->toHaveKeys(['project', 'scan', 'ci', 'gate', 'github', 'exit_code', 'error'])
        ->and($stdout)->not->toContain("\x1b[") // no ANSI escape sequences anywhere on stdout
        ->and($stderr)->not->toContain("\x1b[")
        ->and($process->getExitCode())->toBe($decoded['exit_code']);
});

it('reports an invalid path as a pure JSON operational error on stdout, still nothing else', function () {
    $process = ciSubprocess(['/definitely/not/a/real/path', '--json'], $this->env);

    $decoded = json_decode(trim($process->getOutput()), true);

    expect(json_last_error())->toBe(JSON_ERROR_NONE)
        ->and($decoded['exit_code'])->toBe(3)
        ->and($decoded['error'])->not->toBeNull()
        ->and($process->getExitCode())->toBe(3);
});

it('keeps stdout JSON-only even with a dirty worktree and a stale-migration notice suppressed', function () {
    $repo = GitFixture::repository();
    $repo->write('dirty.txt', 'uncommitted');

    $process = ciSubprocess([$repo->path, '--json'], $this->env);

    $decoded = json_decode(trim($process->getOutput()), true);

    expect(json_last_error())->toBe(JSON_ERROR_NONE)
        ->and($decoded['scan']['source']['dirty'])->toBeTrue()
        // gate was never configured for this throwaway DB -> not evaluated.
        ->and($decoded['exit_code'])->toBe(4);
});

it('is quiet on stdout by design when --json is NOT used — human progress goes to the normal command output instead', function () {
    $repo = GitFixture::repository();

    $process = ciSubprocess([$repo->path], $this->env);

    expect($process->getOutput())->toContain('Exit code:')
        ->and($process->getExitCode())->toBe(4);
});
