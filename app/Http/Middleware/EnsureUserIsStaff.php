<?php

namespace App\Http\Middleware;

use App\Policies\UserPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Gates Owner/Admin-tier areas (project registration, the Settings →
 * Users list — see docs/self-hosting.md's authorization model). Renamed
 * from `EnsureUserIsAdmin` (Phase 7.1.2) now that Owner exists alongside
 * Admin (Phase 7.1.3) — a plain "is admin" check would incorrectly lock
 * the Owner out of these same areas. Applied AFTER `auth`, so
 * `$request->user()` is always present here. Finer-grained distinctions
 * WITHIN this staff-only area (who may act on whom) live in
 * {@see UserPolicy}, not here — this middleware only draws
 * the outer "User role has no business being here at all" line.
 *
 * 404, not 403: this route's very existence isn't meaningful information
 * for a non-staff authenticated user, matching this codebase's existing
 * cross-project IDOR guard style (see ProjectScansController) and its own
 * predecessor's convention.
 */
class EnsureUserIsStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isUser() ?? true) {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }
}
