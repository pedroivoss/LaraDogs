<?php

namespace App\Console\Commands\Mcp;

use App\Models\McpToken;
use Illuminate\Console\Command;

/**
 * Lists tokens for the operator. It can only ever show the public id, label,
 * scope, principal and timestamps — the secret is not stored and cannot be
 * shown.
 */
final class McpTokenListCommand extends Command
{
    protected $signature = 'laradogs:mcp:token-list';

    protected $description = 'List MCP tokens (never their secrets)';

    public function handle(): int
    {
        $rows = McpToken::query()->with('user:id,email')->orderBy('id')->get()->map(fn (McpToken $t): array => [
            $t->token_id, $t->name, $t->scope->value, $t->user->email,
            $t->last_used_at?->toDateTimeString() ?? '—',
            $t->isRevoked() ? 'revoked '.$t->revoked_at?->toDateTimeString() : 'active',
        ])->all();

        $this->table(['Id', 'Name', 'Scope', 'User', 'Last used', 'State'], $rows);

        return self::SUCCESS;
    }
}
