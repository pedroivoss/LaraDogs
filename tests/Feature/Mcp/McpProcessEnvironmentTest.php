<?php

use App\Audit\Analyzers\Composer\ComposerAuditAnalyzer;
use App\Audit\Analyzers\Npm\NpmAuditAnalyzer;
use App\Audit\Analyzers\Semgrep\SemgrepAnalyzer;
use App\Audit\Engine\Process\ProcessCommand;
use App\Audit\Engine\Process\SymfonyProcessRunner;
use App\Audit\Source\Git\GitRepositoryInspector;
use App\Mcp\Auth\EnvironmentMcpTokenSource;
use Tests\TestCase;

/*
 * The MCP credential lives in the MCP server process' environment
 * (LARADOGS_MCP_TOKEN). It must NEVER reach a child process LaraDogs spawns on
 * a target's behalf — Git metadata inspection or any analyzer subprocess.
 * Fake material only.
 */

uses(TestCase::class);

const MCP_FAKE_TOKEN = 'ldmcp_0123456789abcdef_0000000000000000000000000000000000000000000000000000000000000000';

beforeEach(function () {
    putenv(EnvironmentMcpTokenSource::VARIABLE.'='.MCP_FAKE_TOKEN);
    $_SERVER[EnvironmentMcpTokenSource::VARIABLE] = MCP_FAKE_TOKEN;
    $_ENV[EnvironmentMcpTokenSource::VARIABLE] = MCP_FAKE_TOKEN;
    $this->dir = sys_get_temp_dir().'/laradogs-mcp-env-'.bin2hex(random_bytes(6));
    mkdir($this->dir);
});

afterEach(function () {
    putenv(EnvironmentMcpTokenSource::VARIABLE);
    unset($_SERVER[EnvironmentMcpTokenSource::VARIABLE], $_ENV[EnvironmentMcpTokenSource::VARIABLE]);

    foreach (glob($this->dir.'/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($this->dir);
});

/** Runs a child that reports whether the token variable is visible to it. */
function mcpChildSees(SymfonyProcessRunner $runner, array $environment, string $cwd): string
{
    return $runner->run(new ProcessCommand(
        argv: [PHP_BINARY, '-r', 'echo getenv("LARADOGS_MCP_TOKEN") === false ? "ABSENT" : "PRESENT";'],
        workingDirectory: $cwd,
        environment: $environment,
    ))->stdout;
}

it('does not put the MCP variable in the configured child-process allowlist', function () {
    $allowlist = array_map('strtoupper', (array) config('laradogs.process.env_allowlist'));

    expect($allowlist)->not->toContain('LARADOGS_MCP_TOKEN')
        ->and(array_filter($allowlist, fn ($k) => str_starts_with((string) $k, 'LARADOGS_')))->toBe([]);
});

it('keeps the MCP token out of the environment of every analyzer subprocess', function (string $analyzerClass, string $envMethod) {
    $analyzer = app($analyzerClass);
    $environment = (fn () => $this->{$envMethod}())->call($analyzer);

    expect($environment)->not->toHaveKey('LARADOGS_MCP_TOKEN')
        ->and(json_encode($environment))->not->toContain(MCP_FAKE_TOKEN)
        // ...and the real runner, which strips everything not allowlisted, agrees:
        ->and(mcpChildSees(new SymfonyProcessRunner, $environment, $this->dir))->toBe('ABSENT');
})->with([
    'composer audit' => [ComposerAuditAnalyzer::class, 'composerEnv'],
    'npm audit' => [NpmAuditAnalyzer::class, 'npmEnv'],
    'semgrep' => [SemgrepAnalyzer::class, 'semgrepEnv'],
]);

it('keeps the MCP token out of the Git metadata subprocess environment', function () {
    // A stand-in `git` that records the COMPLETE environment it was started with.
    $dump = $this->dir.'/env.dump';
    $git = $this->dir.'/fake-git';
    file_put_contents($git, "#!/bin/sh\nenv > '{$dump}'\nexit 1\n");
    chmod($git, 0755);

    $inspector = new GitRepositoryInspector(
        runner: new SymfonyProcessRunner(65_536),
        binary: $git,
        home: $this->dir,
    );
    $inspector->inspect($this->dir);

    expect(file_exists($dump))->toBeTrue('the stand-in git was not executed — the test would prove nothing')
        ->and(file_get_contents($dump))->not->toContain('LARADOGS_MCP_TOKEN')->not->toContain(MCP_FAKE_TOKEN);
});

it('control: the same child DOES see the variable when it is explicitly passed (the check is not vacuous)', function () {
    expect(mcpChildSees(new SymfonyProcessRunner, ['LARADOGS_MCP_TOKEN' => MCP_FAKE_TOKEN], $this->dir))->toBe('PRESENT');
});
