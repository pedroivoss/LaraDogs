<?php

namespace App\Http\Requests\Settings\Users;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A FormRequest's `authorize()` returning false throws a 403 by default —
 * overridden here to 404, matching every other Owner/Admin-area denial in
 * this codebase (see App\Http\Middleware\EnsureUserIsStaff and
 * App\Http\Controllers\Settings\UsersController::authorizeTarget()): an
 * Admin probing whether a `{user}` id belongs to the Owner or another
 * Admin must see the exact same response an id that doesn't exist at all
 * would produce.
 */
trait DeniesWithNotFound
{
    protected function failedAuthorization(): void
    {
        throw new NotFoundHttpException;
    }
}
