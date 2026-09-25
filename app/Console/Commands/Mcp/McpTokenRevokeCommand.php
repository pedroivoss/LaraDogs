<?php

namespace App\Console\Commands\Mcp;

use App\Mcp\Auth\McpTokenService;
use Illuminate\Console\Command;

final class McpTokenRevokeCommand extends Command
{
    protected $signature = 'laradogs:mcp:token-revoke {token_id : The token id (see laradogs:mcp:token-list)}';

    protected $description = 'Revoke an MCP token (takes effect on its very next request)';

    public function handle(McpTokenService $service): int
    {
        if (! $service->revoke((string) $this->argument('token_id'))) {
            $this->components->error('No MCP token with that id.');

            return self::FAILURE;
        }

        $this->components->info('MCP token revoked.');

        return self::SUCCESS;
    }
}
