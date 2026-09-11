<?php

namespace App\Http\Controllers;

use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public `/` landing page. Unlike the stock Laravel starter-kit
 * welcome page this replaces, it needs one piece of server state:
 * whether an administrator has been bootstrapped yet (see
 * `laradogs:user:create-admin`) — the login page uses this to show a
 * generic "not configured yet" message instead of a normal login form,
 * without ever revealing environment/configuration details.
 */
final class WelcomeController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('welcome', [
            'has_administrator' => User::query()->where('is_admin', true)->exists(),
        ]);
    }
}
