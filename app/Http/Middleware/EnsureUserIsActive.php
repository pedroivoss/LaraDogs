<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces `users.is_active` on every request, not just at login — see
 * `App\Providers\FortifyServiceProvider::configureActions()` for the
 * login-time check via `Fortify::authenticateUsing()`. Registered
 * globally in the `web` middleware group (bootstrap/app.php), so it runs
 * for every request; a no-op for guests (`$request->user()` is null).
 *
 * This is what makes deactivation take effect immediately for an
 * ALREADY-authenticated session, not merely block future logins: the
 * live `is_active` column is re-read from the database on every request
 * (Eloquent never caches a model across requests), so the very next
 * request after an Owner/Admin flips it force-logs the session out —
 * no separate session-invalidation bookkeeping needed.
 *
 * Deliberately placed AFTER the session is already resolved but applies
 * to every route (protected or not) rather than only `auth`-gated ones,
 * so a request mid-flight through, say, `/settings/*` can't slip through
 * on a route that forgot to chain this — there is nothing to forget.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        return $next($request);
    }
}
