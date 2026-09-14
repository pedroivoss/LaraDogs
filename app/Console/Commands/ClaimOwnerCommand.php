<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Promotes an EXISTING account to Instance Owner — for exactly one
 * scenario: an installation upgraded from Phase 7.1.2 (where only
 * `is_admin` existed, no Owner concept) that had MORE THAN ONE admin
 * account. The upgrade migration (`2026_09_11_000001_replace_is_admin_with_role_on_users_table`)
 * deliberately leaves all of them as Admin rather than silently guessing
 * which one should become Owner — see that migration's docblock. This
 * command is how the operator makes that choice explicitly, once, after
 * upgrading.
 *
 * Unlike `laradogs:user:create-owner`, this never creates a new account —
 * only promotes one that already exists — and still refuses outright if
 * an Owner already exists (the same single-Owner invariant, see
 * App\Policies\UserPolicy's docblock).
 */
final class ClaimOwnerCommand extends Command
{
    protected $signature = 'laradogs:user:claim-owner
        {email : Email of the existing account to promote to Instance Owner}';

    protected $description = 'Promote an existing account to Instance Owner (post-upgrade, multiple-prior-admin scenario)';

    public function handle(): int
    {
        if (User::query()->where('role', Role::Owner)->exists()) {
            $this->components->error('An Instance Owner already exists — this command only applies when none does yet.');

            return self::FAILURE;
        }

        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->components->error("No account found with email: {$email}");

            return self::FAILURE;
        }

        if ($user->isOwner()) {
            $this->components->error('That account is already the Instance Owner.');

            return self::FAILURE;
        }

        // Direct property assignment, not update([...])/fill([...]) —
        // `role` is deliberately not in User's #[Fillable] list (see that
        // model's docblock), so a mass-assignment call here would
        // silently no-op instead of raising an error.
        $user->role = Role::Owner;
        $user->save();

        $this->components->info("{$user->email} is now the Instance Owner.");

        return self::SUCCESS;
    }
}
