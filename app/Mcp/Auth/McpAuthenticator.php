<?php

namespace App\Mcp\Auth;

use App\Mcp\Support\McpError;
use App\Mcp\Support\McpErrorCode;
use App\Models\McpToken;

/**
 * Verifies a raw MCP credential (`ldmcp_<token_id>_<secret>`).
 *
 * - The public `token_id` is an indexed lookup key: at most ONE row is ever
 *   fetched — there is no scan over token hashes.
 * - Only the SHA-256 of the (256-bit random) secret is stored; comparison is
 *   constant-time (`hash_equals`), and it is performed even when the id is
 *   unknown so response timing does not reveal which ids exist.
 * - Every failure (malformed, unknown, wrong secret, revoked, inactive user)
 *   yields the SAME generic error — no oracle — and the raw credential never
 *   appears in an error, a log line or an exception message.
 */
final class McpAuthenticator
{
    private const int MAX_TOKEN_LENGTH = 128;

    private const string PATTERN = '/^ldmcp_([0-9a-f]{16})_([0-9a-f]{64})$/';

    /** A dummy hash so an unknown token id costs the same comparison. */
    private const string DUMMY_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function authenticate(?string $raw): McpPrincipal
    {
        $failure = new McpError(McpErrorCode::Unauthenticated, 'A valid MCP credential is required (LARADOGS_MCP_TOKEN).');

        if ($raw === null || strlen($raw) > self::MAX_TOKEN_LENGTH || preg_match(self::PATTERN, $raw, $m) !== 1) {
            throw $failure;
        }

        [, $tokenId, $secret] = $m;

        $token = McpToken::query()->where('token_id', $tokenId)->first();
        $valid = hash_equals($token instanceof McpToken ? $token->secret_hash : self::DUMMY_HASH, hash('sha256', $secret));

        if ($token === null || ! $valid || $token->isRevoked()) {
            throw $failure;
        }

        $user = $token->user;

        if ($user === null || ! $user->is_active) {
            throw $failure;
        }

        $this->touch($token);

        return new McpPrincipal($token, $user);
    }

    /**
     * `last_used_at` is informational; write it at most once a minute so a
     * chatty client does not turn reads into a write per call.
     */
    private function touch(McpToken $token): void
    {
        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            McpToken::query()->whereKey($token->id)->update(['last_used_at' => now()]);
        }
    }
}
