<?php

use App\Mcp\Auth\McpAuthenticator;
use App\Mcp\Auth\McpScope;
use App\Mcp\Auth\McpTokenService;
use App\Mcp\Support\McpError;
use App\Mcp\Support\McpErrorCode;
use App\Mcp\Tools\ListProjects;
use App\Models\McpToken;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\Mcp\McpWorld;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('returns the plaintext token only at creation and stores only a hash', function () {
    $user = McpWorld::user(Role::Admin);

    $created = app(McpTokenService::class)->create($user, 'ide', McpScope::Audit);
    $row = McpToken::query()->firstOrFail();

    expect($created['token'])->toMatch('/^ldmcp_[0-9a-f]{16}_[0-9a-f]{64}$/')
        ->and($row->secret_hash)->toBe(hash('sha256', substr($created['token'], strlen('ldmcp_') + 17)))
        ->and(json_encode(DB::table('mcp_tokens')->get()))->not->toContain(substr($created['token'], -64))
        ->and(array_keys($row->toArray()))->not->toContain('secret_hash');
});

it('authenticates a valid token and rejects malformed, unknown and wrong-secret ones with one generic error', function () {
    $user = McpWorld::user(Role::User);
    $token = app(McpTokenService::class)->create($user, 't', McpScope::Read)['token'];
    $auth = app(McpAuthenticator::class);
    $tokenId = substr($token, 6, 16);

    expect($auth->authenticate($token)->user->is($user))->toBeTrue();

    foreach ([null, '', 'garbage', 'ldmcp_short', 'ldmcp_'.str_repeat('a', 16).'_'.str_repeat('b', 64), 'ldmcp_'.$tokenId.'_'.str_repeat('0', 64), str_repeat('x', 500)] as $bad) {
        try {
            $auth->authenticate($bad);
            $this->fail('expected rejection');
        } catch (McpError $e) {
            expect($e->errorCode)->toBe(McpErrorCode::Unauthenticated)->and($e->getMessage())->not->toContain((string) $bad === '' ? 'zzz' : substr((string) $bad, 0, 12));
        }
    }
});

it('rejects a revoked token on its very next request', function () {
    $user = McpWorld::user(Role::Admin);
    $token = McpWorld::token($user, McpScope::Audit);
    expect(McpWorld::errorCode(McpWorld::call(ListProjects::class)))->toBeNull();

    app(McpTokenService::class)->revoke(substr($token, 6, 16));

    expect(McpWorld::errorCode(McpWorld::call(ListProjects::class)))->toBe('unauthenticated');
});

it('rejects the token of a deactivated user on the next request', function () {
    $user = McpWorld::user(Role::User);
    McpWorld::token($user);
    expect(McpWorld::errorCode(McpWorld::call(ListProjects::class)))->toBeNull();

    $user->forceFill(['is_active' => false])->save();

    expect(McpWorld::errorCode(McpWorld::call(ListProjects::class)))->toBe('unauthenticated');
});

it('rejects a call with no credential at all', function () {
    McpWorld::useToken(null);

    expect(McpWorld::errorCode(McpWorld::call(ListProjects::class)))->toBe('unauthenticated');
});

it('refuses to mint an audit-scope token for a plain User, or a token for an inactive user', function () {
    expect(fn () => app(McpTokenService::class)->create(McpWorld::user(Role::User), 'x', McpScope::Audit))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(McpTokenService::class)->create(McpWorld::user(Role::Admin, active: false), 'x', McpScope::Read))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(McpTokenService::class)->create(McpWorld::user(Role::Admin), '   ', McpScope::Read))->toThrow(InvalidArgumentException::class);
});

it('never leaks the token into errors or logs', function () {
    Log::spy();
    $user = McpWorld::user(Role::User);
    $token = McpWorld::token($user);
    app(McpTokenService::class)->revoke(substr($token, 6, 16));

    $result = McpWorld::call(ListProjects::class);

    expect(json_encode($result))->not->toContain(substr($token, -64))->not->toContain($token);
    Log::shouldNotHaveReceived('info', fn (...$args) => str_contains(json_encode($args), substr($token, -64)));
});

it('looks a token up by its public id with a single bounded query', function () {
    $user = McpWorld::user(Role::User);
    foreach (range(1, 5) as $i) {
        app(McpTokenService::class)->create($user, "t{$i}", McpScope::Read);
    }
    $token = app(McpTokenService::class)->create($user, 'target', McpScope::Read)['token'];

    DB::enableQueryLog();
    app(McpAuthenticator::class)->authenticate($token);
    $selects = collect(DB::getQueryLog())->filter(fn ($q) => str_starts_with($q['query'], 'select'))->pluck('query');
    DB::disableQueryLog();

    // Identifier quoting differs per driver ("x" on SQLite, `x` on MySQL).
    expect(str_replace(['"', '`'], '', (string) $selects->first()))->toContain('token_id = ?')->and($selects->count())->toBeLessThanOrEqual(2);
});

it('updates last_used_at at most once a minute', function () {
    $user = McpWorld::user(Role::User);
    $token = app(McpTokenService::class)->create($user, 't', McpScope::Read)['token'];
    $auth = app(McpAuthenticator::class);

    $auth->authenticate($token);
    $first = McpToken::query()->firstOrFail()->last_used_at;
    $this->travel(20)->seconds();
    $auth->authenticate($token);

    expect($first)->not->toBeNull()->and(McpToken::query()->firstOrFail()->last_used_at->equalTo($first))->toBeTrue();
});

it('creates, lists and revokes tokens from the CLI, showing the secret once and never again', function () {
    $admin = McpWorld::user(Role::Admin);

    expect(Artisan::call('laradogs:mcp:token-create', ['email' => $admin->email, '--name' => 'cli', '--scope' => 'audit']))->toBe(0);
    $out = Artisan::output();
    preg_match('/ldmcp_[0-9a-f]{16}_[0-9a-f]{64}/', $out, $m);
    expect($m)->not->toBe([]);

    Artisan::call('laradogs:mcp:token-list');
    expect(Artisan::output())->toContain('cli')->not->toContain(substr($m[0], -64));

    $id = substr($m[0], 6, 16);
    expect(Artisan::call('laradogs:mcp:token-revoke', ['token_id' => $id]))->toBe(0)
        ->and(McpToken::query()->firstOrFail()->isRevoked())->toBeTrue()
        ->and(Artisan::call('laradogs:mcp:token-revoke', ['token_id' => 'nope']))->toBe(1);
});

it('does not create a token for anyone by default (no fake/default credential)', function () {
    McpWorld::user(Role::Owner);

    expect(McpToken::query()->count())->toBe(0);
});
