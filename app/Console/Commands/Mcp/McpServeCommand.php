<?php

namespace App\Console\Commands\Mcp;

use App\Mcp\Auth\McpAuthenticator;
use App\Mcp\Auth\McpTokenSource;
use App\Mcp\Support\McpError;
use Illuminate\Console\Command;
use Laravel\Mcp\Server\Registrar;

/**
 * `php artisan laradogs:mcp` — the canonical launch command of the LaraDogs
 * MCP server (stdio). The MCP CLIENT spawns it and speaks JSON-RPC over
 * stdin/stdout.
 *
 * STDOUT IS THE PROTOCOL: nothing but JSON-RPC messages may ever be written
 * there, so this command prints NOTHING itself and reports every startup
 * problem to STDERR. The credential comes from `LARADOGS_MCP_TOKEN` in the
 * environment (never an argument — it would show in `ps`).
 *
 * The token is validated once here so a wrong/revoked credential fails fast
 * with a clear message — but that is only a convenience: every tool call
 * re-authenticates and re-authorizes against the database, so revocation,
 * deactivation and role changes take effect on the very next request.
 */
final class McpServeCommand extends Command
{
    protected $signature = 'laradogs:mcp';

    protected $description = 'Start the LaraDogs MCP server on stdio (stdout is the protocol; diagnostics go to stderr)';

    public function handle(Registrar $registrar, McpAuthenticator $authenticator, McpTokenSource $source): int
    {
        try {
            $authenticator->authenticate($source->token());
        } catch (McpError $error) {
            fwrite(STDERR, "laradogs:mcp: {$error->getMessage()}\n");

            return self::FAILURE;
        }

        $server = $registrar->getLocalServer('laradogs');

        if ($server === null) {
            fwrite(STDERR, "laradogs:mcp: the MCP server is not registered.\n");

            return self::FAILURE;
        }

        $server();

        return self::SUCCESS;
    }
}
