<?php

namespace App\Mcp\Auth;

/**
 * Where the running MCP process gets the caller's credential (Phase 11).
 * For the stdio transport the process is spawned by the MCP client with the
 * token in its environment — see {@see EnvironmentMcpTokenSource}. Kept as a
 * seam so a future transport can supply a per-request credential without
 * touching authorization.
 */
interface McpTokenSource
{
    public function token(): ?string;
}
