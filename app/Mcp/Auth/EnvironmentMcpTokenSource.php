<?php

namespace App\Mcp\Auth;

/**
 * Reads `LARADOGS_MCP_TOKEN` from the process environment — the token is
 * never a CLI argument (it would show in `ps`) and never a config file.
 */
final class EnvironmentMcpTokenSource implements McpTokenSource
{
    public const string VARIABLE = 'LARADOGS_MCP_TOKEN';

    public function token(): ?string
    {
        $value = getenv(self::VARIABLE);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
