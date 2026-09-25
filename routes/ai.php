<?php

use App\Mcp\LaraDogsServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP servers (Phase 11)
|--------------------------------------------------------------------------
|
| ONE server, ONE transport: LOCAL (stdio). The MCP client spawns
| `php artisan laradogs:mcp` as a child process; there is no HTTP endpoint and
| therefore no network port. Authentication is a dedicated MCP token from the
| process environment (`LARADOGS_MCP_TOKEN`) — see docs/integrations/mcp.md.
|
*/

Mcp::local('laradogs', LaraDogsServer::class);
