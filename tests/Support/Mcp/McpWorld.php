<?php

namespace Tests\Support\Mcp;

use App\Mcp\Auth\McpScope;
use App\Mcp\Auth\McpTokenService;
use App\Mcp\Auth\McpTokenSource;
use App\Mcp\LaraDogsServer;
use App\Models\Audit\Project;
use App\Models\Role;
use App\Models\User;
use Laravel\Mcp\Server\Testing\TestResponse;

/**
 * Test scaffolding for the MCP surface: users, tokens (created through the
 * REAL token service — plaintext only ever lives in the test), the token
 * source binding, and a `call()` that drives the REAL server and returns the
 * structured result. Fake tokens/data only.
 */
final class McpWorld
{
    public static function user(?Role $role = Role::User, bool $active = true): User
    {
        $factory = User::factory();
        $factory = match ($role) {
            Role::Owner => $factory->owner(),
            Role::Admin => $factory->admin(),
            default => $factory,
        };

        return $active ? $factory->create() : $factory->inactive()->create();
    }

    /** Creates a token AND makes it the caller's credential. */
    public static function token(User $user, McpScope $scope = McpScope::Read): string
    {
        $token = app(McpTokenService::class)->create($user, 'test', $scope)['token'];
        self::useToken($token);

        return $token;
    }

    public static function useToken(?string $token): void
    {
        app()->instance(McpTokenSource::class, new class($token) implements McpTokenSource
        {
            public function __construct(private ?string $value) {}

            public function token(): ?string
            {
                return $this->value;
            }
        });
    }

    public static function project(string $name = 'Demo', ?string $path = null): Project
    {
        return Project::query()->create(['name' => $name, 'path' => $path ?? '/tmp/laradogs-mcp-'.bin2hex(random_bytes(4))]);
    }

    /**
     * @param  class-string  $tool
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed> the tool's structured content (result or {error:{code,...}})
     */
    public static function call(string $tool, array $arguments = []): array
    {
        $response = LaraDogsServer::tool($tool, $arguments);

        return self::structured($response) ?? [];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function structured(TestResponse $response): ?array
    {
        return (fn () => $this->structuredContent())->call($response);
    }

    public static function errorCode(array $result): ?string
    {
        return $result['error']['code'] ?? null;
    }
}
