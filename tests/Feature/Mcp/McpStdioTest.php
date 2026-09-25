<?php

use Symfony\Component\Process\Process;

/*
 * REAL protocol validation: the actual `php artisan laradogs:mcp` process
 * over stdio (JSON-RPC lines), against a throwaway SQLite database. stdout
 * and stderr are captured as SEPARATE streams.
 */

beforeEach(function () {
    $this->db = sys_get_temp_dir().'/laradogs-mcp-stdio-'.bin2hex(random_bytes(6)).'.sqlite';
    touch($this->db);
    $this->env = [
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->db, 'DB_HOST' => '', 'DB_PORT' => '', 'DB_USERNAME' => '', 'DB_PASSWORD' => '',
        'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'CACHE_STORE' => 'array', 'LOG_CHANNEL' => 'single', 'LOG_STACK' => 'single',
        'LARADOGS_MCP_TOKEN' => false, 'NO_COLOR' => '1',
    ];
    $base = dirname(__DIR__, 3);
    (new Process(['php', 'artisan', 'migrate', '--force', '--no-interaction'], $base, $this->env))->mustRun();

    // A user, an audit token and a project — created through the REAL services.
    $script = <<<'PHP'
        $u = App\Models\User::factory()->admin()->create();
        $t = app(App\Mcp\Auth\McpTokenService::class)->create($u, 'stdio-test', App\Mcp\Auth\McpScope::Audit);
        $p = App\Models\Audit\Project::query()->create(['name' => 'Stdio Demo', 'path' => '/tmp/laradogs-stdio-demo']);
        echo 'TOKEN='.$t['token'].';PROJECT='.$p->public_id;
    PHP;
    $out = (new Process(['php', 'artisan', 'tinker', '--execute='.$script], $base, $this->env))->mustRun()->getOutput();
    preg_match('/TOKEN=(ldmcp_[0-9a-f]{16}_[0-9a-f]{64});PROJECT=(\w{26})/', $out, $m);
    $this->token = $m[1];
    $this->projectId = $m[2];
});

afterEach(fn () => @unlink($this->db));

function stdioRun(array $env, array $messages, ?string $token): Process
{
    $base = dirname(__DIR__, 3);
    $process = new Process(['php', 'artisan', 'laradogs:mcp'], $base, [...$env, 'LARADOGS_MCP_TOKEN' => $token ?? false]);
    $process->setTimeout(60);
    $process->setInput(implode("\n", array_map(fn ($m) => json_encode($m), $messages))."\n");
    $process->run();

    return $process;
}

function stdioMessages(): array
{
    return [
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass, 'clientInfo' => ['name' => 'pest', 'version' => '1']]],
        ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
        ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
    ];
}

/** @return list<array<string,mixed>> */
function stdioLines(Process $p): array
{
    return array_map(fn (string $l) => json_decode($l, true, flags: JSON_THROW_ON_ERROR), array_values(array_filter(explode("\n", trim($p->getOutput())))));
}

it('speaks ONLY JSON-RPC on stdout, discovers the tools and shuts down cleanly on EOF', function () {
    $p = stdioRun($this->env, [
        ...stdioMessages(),
        ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'laradogs.list_projects', 'arguments' => new stdClass]],
        ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'laradogs.get_project', 'arguments' => ['project_id' => $this->projectId]]],
    ], $this->token);

    $lines = stdioLines($p); // every stdout line MUST be valid JSON
    $byId = collect($lines)->keyBy('id');

    expect($p->getExitCode())->toBe(0)
        ->and($lines)->each->toHaveKey('jsonrpc')
        ->and($p->getOutput())->not->toContain("\x1b[")
        ->and($byId[1]['result']['serverInfo']['name'])->toBe('LaraDogs')
        ->and($byId[1]['result']['capabilities'])->toHaveKey('tools')->not->toHaveKey('resources')->not->toHaveKey('prompts')
        ->and(collect($byId[2]['result']['tools'])->pluck('name'))->toContain('laradogs.run_project_audit', 'laradogs.list_findings')
        ->and($byId[3]['result']['structuredContent']['projects'][0]['id'])->toBe($this->projectId)
        ->and($byId[4]['result']['structuredContent']['project']['name'])->toBe('Stdio Demo');
});

it('answers an unknown tool and malformed arguments with safe errors and stays alive for the next request', function () {
    $p = stdioRun($this->env, [
        ...stdioMessages(),
        ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'laradogs.nope', 'arguments' => new stdClass]],
        ['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call', 'params' => ['name' => 'laradogs.list_projects', 'arguments' => ['limit' => 100000]]],
        ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'laradogs.list_projects', 'arguments' => new stdClass]],
    ], $this->token);

    $byId = collect(stdioLines($p))->keyBy('id');

    expect($byId[5])->toHaveKey('error')
        ->and($byId[6]['result']['isError'])->toBeTrue()
        ->and($byId[6]['result']['structuredContent']['error']['code'])->toBe('invalid_arguments')
        ->and($byId[7]['result']['isError'] ?? false)->toBeFalse()
        ->and($byId[7]['result']['structuredContent']['projects'])->toHaveCount(1)
        ->and($p->getOutput())->not->toContain('SQLSTATE')->not->toContain('vendor/');
});

it('enforces authorization per call over the real process: a read token cannot start audits', function () {
    $base = dirname(__DIR__, 3);
    $script = '$u = App\Models\User::factory()->create(); echo app(App\Mcp\Auth\McpTokenService::class)->create($u, "ro", App\Mcp\Auth\McpScope::Read)["token"];';
    $readToken = trim((new Process(['php', 'artisan', 'tinker', '--execute='.$script], $base, $this->env))->mustRun()->getOutput());

    $p = stdioRun($this->env, [
        ...stdioMessages(),
        ['jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call', 'params' => ['name' => 'laradogs.run_project_audit', 'arguments' => ['project_id' => $this->projectId]]],
    ], $readToken);

    $byId = collect(stdioLines($p))->keyBy('id');

    expect($byId[8]['result']['structuredContent']['error']['code'])->toBe('forbidden');
});

it('refuses to start without a valid token: nothing on stdout, a clear message on stderr, non-zero exit', function () {
    foreach ([null, 'ldmcp_'.str_repeat('0', 16).'_'.str_repeat('0', 64), 'not-a-token'] as $bad) {
        $p = stdioRun($this->env, stdioMessages(), $bad);

        expect($p->getExitCode())->not->toBe(0)
            ->and(trim($p->getOutput()))->toBe('')
            ->and($p->getErrorOutput())->toContain('laradogs:mcp')
            ->and($p->getErrorOutput())->not->toContain((string) $bad === '' ? 'zzz' : $bad);
    }
});

it('keeps the stderr diagnostics out of the protocol stream and never prints the token', function () {
    $p = stdioRun($this->env, [...stdioMessages(), ['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call', 'params' => ['name' => 'laradogs.list_projects', 'arguments' => new stdClass]]], $this->token);

    expect($p->getOutput())->not->toContain($this->token)->and($p->getErrorOutput())->not->toContain($this->token)
        ->and(collect(stdioLines($p))->pluck('id')->all())->toContain(9);
});
