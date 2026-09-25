<?php

namespace App\Mcp\Auth;

use App\Models\McpToken;
use App\Models\User;
use InvalidArgumentException;

/**
 * Creates and revokes MCP tokens (Phase 11) — the only writer of
 * `mcp_tokens`. The plaintext token exists only in the return value of
 * {@see create()}: it is never stored, logged or retrievable afterwards.
 */
final class McpTokenService
{
    public const string PREFIX = 'ldmcp';

    /**
     * @return array{token: string, model: McpToken} the plaintext token is shown ONCE, by the caller
     */
    public function create(User $user, string $name, McpScope $scope): array
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 100) {
            throw new InvalidArgumentException('A token name (1-100 characters) is required.');
        }

        if (! $user->is_active) {
            throw new InvalidArgumentException('Tokens can only be created for an active user.');
        }

        if ($scope->allowsAudit() && $user->isUser()) {
            throw new InvalidArgumentException("The 'audit' scope requires an Owner or Admin user.");
        }

        $tokenId = bin2hex(random_bytes(8));
        $secret = bin2hex(random_bytes(32));

        $model = McpToken::query()->create([
            'token_id' => $tokenId,
            'secret_hash' => hash('sha256', $secret),
            'user_id' => $user->id,
            'name' => $name,
            'scope' => $scope,
        ]);

        return ['token' => self::PREFIX."_{$tokenId}_{$secret}", 'model' => $model];
    }

    public function revoke(string $tokenId): bool
    {
        $token = McpToken::query()->where('token_id', $tokenId)->first();

        if ($token === null) {
            return false;
        }

        if (! $token->isRevoked()) {
            $token->forceFill(['revoked_at' => now()])->save();
        }

        return true;
    }
}
