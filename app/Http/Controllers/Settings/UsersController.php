<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\Users\SetUserPasswordRequest;
use App\Http\Requests\Settings\Users\StoreUserRequest;
use App\Http\Requests\Settings\Users\UpdateUserRequest;
use App\Models\Role;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Owner/Admin user management (Phase 7.1.2, refined into Owner/Admin/User
 * in Phase 7.1.3). Gated by the `staff` route middleware (excludes plain
 * User accounts entirely) — every finer-grained "who may act on whom"
 * decision here delegates to {@see UserPolicy}, never an
 * inline `if ($actor->role === ...)`.
 *
 * Every action that resolves a `{user}` target denies with 404 (not 403)
 * when the policy check fails — matching this codebase's existing
 * IDOR-guard convention (see ProjectScansController,
 * EnsureUserIsStaff) — and, critically, this is what keeps the Owner
 * genuinely invisible to an Admin: a request naming the Owner's id
 * behaves identically to a request naming an id that doesn't exist at
 * all. {@see index()} additionally excludes Owner from the query itself
 * (and, for an Admin actor, excludes other Admins too), so there is
 * never an Owner/other-Admin row in a JSON/Inertia response for a
 * non-Owner actor to inspect in the first place.
 */
final class UsersController extends Controller
{
    public function index(Request $request): Response
    {
        $actor = $request->user();

        $query = User::query()->where('role', '!=', Role::Owner);

        if ($actor->isAdmin()) {
            $query->where('role', Role::User);
        }

        return Inertia::render('settings/users/index', [
            'users' => $query
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'role', 'is_active', 'created_at'])
                ->map(fn (User $user) => $this->userToArray($user)),
            'can_create_admin' => $actor->isOwner(),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('settings/users/create', [
            'can_create_admin' => $request->user()->isOwner(),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $role = $validated['role'] === 'admin' && Gate::forUser($request->user())->allows('createAdmin', User::class)
            ? Role::Admin
            : Role::User;

        $user = new User([
            'name' => $validated['name'],
            'email' => $validated['email'],
        ]);
        $user->password = Hash::make($validated['password']);
        $user->role = $role;
        $user->is_active = true;
        $user->email_verified_at = Carbon::now();
        $user->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User created.')]);

        return to_route('settings.users.index');
    }

    public function edit(Request $request, User $user): Response
    {
        $this->authorizeTarget('manage', $request, $user);

        return Inertia::render('settings/users/edit', [
            'target_user' => $this->userToArray($user),
            'can_change_role' => $request->user()->isOwner(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->authorizeTarget('manage', $request, $user);

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
        $this->authorizeTarget('manage', $request, $user);

        $user->update(['password' => $request->validated()['password']]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password updated.')]);

        return to_route('settings.users.edit', $user);
    }

    public function activate(Request $request, User $user): RedirectResponse
    {
        $this->authorizeTarget('activate', $request, $user);

        // Direct property assignment, never `update([...])`/`fill([...])`
        // — `is_active` is deliberately NOT in User's #[Fillable] list
        // (see that model's docblock), so a mass-assignment call here
        // would silently no-op instead of raising an error.
        $user->is_active = true;
        $user->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account activated.')]);

        return to_route('settings.users.edit', $user);
    }

    public function deactivate(Request $request, User $user): RedirectResponse
    {
        $this->authorizeTarget('deactivate', $request, $user);

        $user->is_active = false;
        $user->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account deactivated.')]);

        return to_route('settings.users.edit', $user);
    }

    public function promote(Request $request, User $user): RedirectResponse
    {
        $this->authorizeTarget('promote', $request, $user);

        // Same reasoning as activate()/deactivate() above — `role` is
        // also deliberately not mass-assignable.
        $user->role = Role::Admin;
        $user->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User promoted to Admin.')]);

        return to_route('settings.users.edit', $user);
    }

    public function demote(Request $request, User $user): RedirectResponse
    {
        $this->authorizeTarget('demote', $request, $user);

        $user->role = Role::User;
        $user->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Admin demoted to User.')]);

        return to_route('settings.users.edit', $user);
    }

    /**
     * Translates a denied {@see UserPolicy} check into 404
     * — see this class's own docblock for why 404, not 403.
     */
    private function authorizeTarget(string $ability, Request $request, User $target): void
    {
        if (Gate::forUser($request->user())->denies($ability, $target)) {
            throw new NotFoundHttpException;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function userToArray(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'is_active' => $user->is_active,
            'created_at' => $user->created_at->toIso8601String(),
        ];
    }
}
