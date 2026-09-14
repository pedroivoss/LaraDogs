<?php

namespace App\Policies;

use App\Http\Controllers\Settings\UsersController;
use App\Models\Role;
use App\Models\User;

/**
 * The single source of truth for who may do what to whom in Settings →
 * Users (Phase 7.1.3) — every controller action delegates here rather
 * than inlining `if ($user->role === ...)` checks (see this phase's own
 * "controllers should remain thin" instruction). Auto-discovered by
 * Laravel for the {@see User} model (standard `App\Policies\{Model}Policy`
 * convention) — no manual registration needed.
 *
 * Core rule, applied everywhere below: the Owner is NEVER a valid target
 * of these actions, not even by themselves — Owner self-service happens
 * through the existing Settings → Profile/Security pages, identically to
 * every other role (see docs/self-hosting.md). This is also what keeps
 * Owner invisible to Admin: {@see UsersController::index()}
 * excludes Owner from the query entirely (never merely hidden in the
 * response), so there is no "target" for an Admin to even attempt this
 * against.
 *
 * Single-Owner enforcement: `promote`/`demote` only ever move a user
 * between Admin and User — nothing here can ever produce a second Owner.
 * The only paths that assign `Role::Owner` are
 * `laradogs:user:create-owner` (refuses if one already exists) and
 * `laradogs:user:claim-owner` (same refusal) — both outside this policy,
 * both CLI-only, deliberately not exposed over HTTP at all.
 */
class UserPolicy
{
    /**
     * View the Settings → Users list. Owner and Admin only — a plain
     * User has no management capability at all.
     */
    public function viewAny(User $actor): bool
    {
        return ! $actor->isUser();
    }

    /**
     * View/edit one target account's name/email, or set its password.
     * Owner may manage Admin and User; Admin may manage User only, never
     * another Admin, never Owner.
     */
    public function manage(User $actor, User $target): bool
    {
        if ($target->isOwner()) {
            return false;
        }

        return match ($actor->role) {
            Role::Owner => true,
            Role::Admin => $target->isUser(),
            Role::User => false,
        };
    }

    /**
     * Create a new account. Owner and Admin both may — the Owner-only
     * restriction is specifically on the ROLE assigned to that new
     * account ({@see createAdmin}), not on creating an account at all.
     */
    public function create(User $actor): bool
    {
        return ! $actor->isUser();
    }

    /**
     * Create a new account WITH the Admin role. Owner only — this is
     * what stops an Admin from ever granting themselves or anyone else
     * Admin-tier access via the create form (the controller forces
     * `role = user` server-side whenever this is false, regardless of
     * what the request body asked for).
     */
    public function createAdmin(User $actor): bool
    {
        return $actor->isOwner();
    }

    /**
     * Activate/deactivate a target account. Same reach as {@see manage},
     * plus: never on yourself (an Admin can't lock themselves out or
     * dodge deactivation this way), and never on the Owner (already
     * covered by {@see manage}, restated here for clarity since this is
     * the action with the most severe consequence).
     */
    public function deactivate(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return false;
        }

        return $this->manage($actor, $target);
    }

    /** @see deactivate — same rule, opposite direction. */
    public function activate(User $actor, User $target): bool
    {
        return $this->deactivate($actor, $target);
    }

    /**
     * User -> Admin. Owner only, and only ever starting from User — an
     * Admin is never "promoted" (there is nowhere higher except Owner,
     * which is deliberately not a promotion target at all — see
     * `laradogs:user:claim-owner`).
     */
    public function promote(User $actor, User $target): bool
    {
        return $actor->isOwner() && $target->isUser();
    }

    /**
     * Admin -> User. Owner only, and only ever starting from Admin.
     */
    public function demote(User $actor, User $target): bool
    {
        return $actor->isOwner() && $target->isAdmin();
    }
}
