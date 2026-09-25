<?php

namespace App\Mcp\Auth;

use App\Models\McpToken;
use App\Models\User;

/**
 * The authenticated actor of ONE tool call: a token plus the user it acts
 * for, loaded fresh from the database for that call. Never cached across
 * calls — role changes, deactivation and revocation take effect on the very
 * next request.
 */
final readonly class McpPrincipal
{
    public function __construct(public McpToken $token, public User $user) {}

    /** The token's scope caps AND the user's current role must allow it. */
    public function canAudit(): bool
    {
        return $this->token->scope->allowsAudit() && ! $this->user->isUser();
    }
}
