<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\Users\SetUserPasswordRequest;
use App\Http\Requests\Settings\Users\StoreUserRequest;
use App\Http\Requests\Settings\Users\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin-only user management (Phase 7.1.2, PART H). Gated by the `admin`
 * route middleware, not by anything in here — every action below also
 * re-checks via each FormRequest's own `authorize()` as defense in depth.
 *
 * Deliberately minimal, per that phase's own "prefer the smaller safe
 * implementation" guidance: no promote/demote, no activate/deactivate, no
 * delete — `is_admin` is set once, only at account creation (the first
 * administrator via `laradogs:user:create-admin`, everyone else always
 * `false` here), and is immutable from this UI for now. That sidesteps
 * "last admin" lockout scenarios entirely rather than half-implementing
 * safety around them; a future phase can add role changes with an
 * explicit last-admin guard if that's ever actually needed.
 */
final class UsersController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('settings/users/index', [
            'users' => User::query()
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'is_admin', 'created_at'])
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_admin' => $user->is_admin,
                    'created_at' => $user->created_at->toIso8601String(),
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('settings/users/create');
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $user = new User([
            'name' => $validated['name'],
            'email' => $validated['email'],
        ]);
        $user->password = Hash::make($validated['password']);
        $user->is_admin = false;
        $user->email_verified_at = Carbon::now();
        $user->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User created.')]);

        return to_route('settings.users.index');
    }

    public function edit(User $user): Response
    {
        return Inertia::render('settings/users/edit', [
            'target_user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
            ],
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $user->fill($request->validated());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User updated.')]);

        return to_route('settings.users.edit', $user);
    }

    public function updatePassword(SetUserPasswordRequest $request, User $user): RedirectResponse
    {
        $user->update(['password' => $request->validated()['password']]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password updated.')]);

        return to_route('settings.users.edit', $user);
    }
}
