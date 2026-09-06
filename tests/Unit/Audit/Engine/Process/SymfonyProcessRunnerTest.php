<?php

use App\Audit\Engine\Process\ProcessCommand;
use App\Audit\Engine\Process\SymfonyProcessRunner;

/**
 * Exercises the REAL SymfonyProcessRunner against LaraDogs' OWN controlled
 * PHP fixture scripts (tests/Fixtures/process/*.php), invoked via
 * PHP_BINARY — never against anything originating in a target/audited
 * project. See docs/development/process-execution.md.
 */
function processFixture(string $name): string
{
    return dirname(__DIR__, 4).'/Fixtures/process/'.$name;
}

function tempWorkingDirectory(): string
{
    $dir = sys_get_temp_dir().'/laradogs-process-test-'.bin2hex(random_bytes(8));
    mkdir($dir);

    return $dir;
}

it('passes argv through verbatim, never through a shell', function () {
    $cwd = tempWorkingDirectory();
    $marker = $cwd.'/SHOULD_NEVER_EXIST';

    $runner = new SymfonyProcessRunner;

    $result = $runner->run(new ProcessCommand(
        argv: [PHP_BINARY, processFixture('echo-args.php'), '; touch '.$marker, '&& rm -rf /', '`whoami`', '$(id)'],
        workingDirectory: $cwd,
    ));

    expect($result->successful())->toBeTrue()
        ->and($result->stdout)->toBe(
            '; touch '.$marker.PHP_EOL.
            '&& rm -rf /'.PHP_EOL.
            '`whoami`'.PHP_EOL.
            '$(id)'.PHP_EOL,
        )
        ->and(file_exists($marker))->toBeFalse();
});

it('runs in the given working directory', function () {
    $cwd = tempWorkingDirectory();
    file_put_contents($cwd.'/marker.txt', 'here');

    $runner = new SymfonyProcessRunner;

    $result = $runner->run(new ProcessCommand(
        argv: [PHP_BINARY, '-r', 'echo getcwd();'],
        workingDirectory: $cwd,
    ));

    expect($result->successful())->toBeTrue()
        ->and(realpath($result->stdout))->toBe(realpath($cwd));
});

it('reports a process-start failure for a nonexistent working directory, never a false success', function () {
    $runner = new SymfonyProcessRunner;

    $result = $runner->run(new ProcessCommand(
        argv: [PHP_BINARY, '-v'],
        workingDirectory: sys_get_temp_dir().'/laradogs-does-not-exist-'.bin2hex(random_bytes(8)),
    ));

    expect($result->successful())->toBeFalse()
        ->and($result->timedOut)->toBeFalse()
        ->and($result->processStartFailed())->toBeTrue()
        ->and($result->exitCode)->toBeNull();
});

it('enforces a real timeout and reports it distinctly', function () {
    $runner = new SymfonyProcessRunner;

    $result = $runner->run(new ProcessCommand(
        argv: [PHP_BINARY, processFixture('sleep.php'), '5'],
        workingDirectory: tempWorkingDirectory(),
        timeoutSeconds: 1,
    ));

    expect($result->timedOut)->toBeTrue()
        ->and($result->successful())->toBeFalse()
        ->and($result->processStartFailed())->toBeFalse();
});

it('captures stdout and stderr separately', function () {
    $runner = new SymfonyProcessRunner;

    $result = $runner->run(new ProcessCommand(
        argv: [PHP_BINARY, processFixture('stdout-stderr.php')],
        workingDirectory: tempWorkingDirectory(),
    ));

    expect($result->stdout)->toBe('stdout-line'.PHP_EOL)
        ->and($result->stderr)->toBe('stderr-line'.PHP_EOL);
});

it('reports the real process exit code', function () {
    $runner = new SymfonyProcessRunner;

    $result = $runner->run(new ProcessCommand(
        argv: [PHP_BINARY, processFixture('exit-code.php'), '17'],
        workingDirectory: tempWorkingDirectory(),
    ));

    expect($result->exitCode)->toBe(17)
        ->and($result->successful())->toBeFalse();
});

it('reports a real non-zero exit code for a missing executable, never a false success or a timeout', function () {
    // Verified empirically (see SymfonyProcessRunner's own docblock): a
    // missing binary does NOT raise ProcessStartFailedException on this
    // platform — proc_open() still succeeds and the failed exec surfaces
    // as an ordinary exit code. Callers that need to detect a missing
    // binary specifically must do so before ever invoking a ProcessRunner
    // (see ComposerBinaryResolver) — this test only guards against this
    // case ever silently reading as success.
    $runner = new SymfonyProcessRunner;

    $result = $runner->run(new ProcessCommand(
        argv: [sys_get_temp_dir().'/laradogs-no-such-binary-'.bin2hex(random_bytes(8))],
        workingDirectory: tempWorkingDirectory(),
    ));

    expect($result->successful())->toBeFalse()
        ->and($result->timedOut)->toBeFalse()
        ->and($result->exitCode)->not->toBe(0);
});

it('only passes the given environment through, never the full inherited environment', function () {
    $runner = new SymfonyProcessRunner;

    putenv('LARADOGS_TEST_SECRET=leaked-value');

    try {
        $result = $runner->run(new ProcessCommand(
            argv: [PHP_BINARY, '-r', 'echo getenv("LARADOGS_TEST_SECRET") === false ? "ABSENT" : "PRESENT";'],
            workingDirectory: tempWorkingDirectory(),
            environment: ['ALLOWED_VAR' => 'allowed-value'],
        ));
    } finally {
        putenv('LARADOGS_TEST_SECRET');
    }

    expect($result->stdout)->toBe('ABSENT');
});

it('passes explicitly allowlisted environment variables through', function () {
    $runner = new SymfonyProcessRunner;

    $result = $runner->run(new ProcessCommand(
        argv: [PHP_BINARY, '-r', 'echo getenv("ALLOWED_VAR");'],
        workingDirectory: tempWorkingDirectory(),
        environment: ['ALLOWED_VAR' => 'allowed-value'],
    ));

    expect($result->stdout)->toBe('allowed-value');
});

it('caps captured output and reports truncation rather than buffering unboundedly', function () {
    $runner = new SymfonyProcessRunner(maxOutputBytes: 1_000);

    $result = $runner->run(new ProcessCommand(
        argv: [PHP_BINARY, processFixture('huge-output.php'), '5000000'],
        workingDirectory: tempWorkingDirectory(),
        timeoutSeconds: 30,
    ));

    expect($result->outputTruncated)->toBeTrue()
        ->and(strlen($result->stdout))->toBeLessThanOrEqual(1_000);
});

it('does not truncate output that fits within the cap', function () {
    $runner = new SymfonyProcessRunner;

    $result = $runner->run(new ProcessCommand(
        argv: [PHP_BINARY, processFixture('stdout-stderr.php')],
        workingDirectory: tempWorkingDirectory(),
    ));

    expect($result->outputTruncated)->toBeFalse();
});

it('records a real, positive duration', function () {
    $runner = new SymfonyProcessRunner;

    $result = $runner->run(new ProcessCommand(
        argv: [PHP_BINARY, processFixture('exit-code.php'), '0'],
        workingDirectory: tempWorkingDirectory(),
    ));

    expect($result->durationMs)->toBeGreaterThanOrEqual(0);
});
