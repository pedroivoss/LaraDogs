<?php

namespace App\Mcp\Auth;

/**
 * The whole scope vocabulary (Phase 11): deliberately two levels. `Audit`
 * implies `Read`. A scope only CAPS what a token may ever do — the acting
 * user's CURRENT role is still checked on every request, so a scope never
 * grants more than the role allows (an `audit` token of a demoted Admin can
 * no longer audit).
 */
enum McpScope: string
{
    case Read = 'read';
    case Audit = 'audit';

    public function allowsRead(): bool
    {
        return true;
    }

    public function allowsAudit(): bool
    {
        return $this === self::Audit;
    }
}
