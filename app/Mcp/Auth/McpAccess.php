<?php

namespace App\Mcp\Auth;

use App\Mcp\Support\McpError;
use App\Mcp\Support\McpErrorCode;

/**
 * THE centralized authorizer of the MCP surface (Phase 11): every tool goes
 * through it, none re-implements a role check. Authentication is repeated on
 * EVERY call, so revocation, deactivation and role changes apply
 * immediately.
 *
 * - read: any active user with a valid token (mirrors the Dashboard: there is
 *   no project-level ACL in LaraDogs, so an authenticated user sees every
 *   project, exactly as in the Dashboard).
 * - audit: the token must carry the `audit` scope AND the user must CURRENTLY
 *   be Owner/Admin (the same reach as the Dashboard's `staff` middleware).
 *
 * Nothing here can be widened by a tool argument or a client capability.
 */
final class McpAccess
{
    public function __construct(private McpTokenSource $source, private McpAuthenticator $authenticator) {}

    public function forRead(): McpPrincipal
    {
        return $this->authenticator->authenticate($this->source->token());
    }

    public function forAudit(): McpPrincipal
    {
        $principal = $this->forRead();

        if (! $principal->token->scope->allowsAudit()) {
            throw new McpError(McpErrorCode::Forbidden, "This token's scope does not allow starting audits (it needs the 'audit' scope).");
        }

        if (! $principal->canAudit()) {
            throw new McpError(McpErrorCode::Forbidden, 'Only an Owner or Admin can start audits.');
        }

        return $principal;
    }
}
