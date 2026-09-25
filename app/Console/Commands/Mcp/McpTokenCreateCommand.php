<?php

namespace App\Console\Commands\Mcp;

use App\Mcp\Auth\McpScope;
use App\Mcp\Auth\McpTokenService;
use App\Models\User;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Creates an MCP token for an EXISTING, active user. The plaintext token is
 * printed exactly once, here, and can never be shown again (only its hash is
 * stored). Nobody receives a token automatically.
 */
final class McpTokenCreateCommand extends Command
{
    protected $signature = 'laradogs:mcp:token-create
        {email : Email of the LaraDogs user the token acts for}
        {--name= : A label for the token (required)}
        {--scope=read : read (inspect only) or audit (also queue audits; Owner/Admin only)}';

    protected $description = 'Create an MCP token (shown once; only its hash is stored)';

    public function handle(McpTokenService $service): int
    {
        $user = User::query()->where('email', (string) $this->argument('email'))->first();
        $scope = McpScope::tryFrom((string) $this->option('scope'));

        if ($user === null) {
            $this->components->error('No user with that email.');

            return self::FAILURE;
        }

        if ($scope === null) {
            $this->components->error('The scope must be "read" or "audit".');

            return self::FAILURE;
        }

        try {
            $created = $service->create($user, (string) $this->option('name'), $scope);
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("MCP token \"{$created['model']->name}\" created (id {$created['model']->token_id}, scope {$scope->value}).");
        $this->components->warn('Copy the token now — it is shown ONLY ONCE and cannot be retrieved later:');
        $this->line($created['token']);

        return self::SUCCESS;
    }
}
