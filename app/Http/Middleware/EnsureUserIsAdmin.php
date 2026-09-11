<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Gates admin-only areas (project registration, user management — see
 * Phase 7.1.2's PART J policy: project registration is admin-only because
 * it grants access to server-mounted filesystem paths). Applied AFTER
 * `auth`, so `$request->user()` is always present here.
 *
 * 404, not 403: an admin-only route's very existence isn't meaningful
 * information for a non-admin authenticated user, matching this
 * codebase's existing cross-project IDOR guard style (see
 * ProjectScansController).
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->is_admin) {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }
}
