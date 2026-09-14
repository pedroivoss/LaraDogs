<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ProvisionsUserAccounts;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Provisions LaraDogs' Instance Owner (Phase 7.1.3) — the canonical
 * command for a fresh installation. Deliberately the ONLY way to create
 * the very first account: see docs/self-hosting.md's security principle,
 * LaraDogs never ships/auto-creates a known default credential
 * (`admin`/`admin`, `owner@laradogs.test`/`password`, or similar). The
 * operator supplies real values, interactively (hidden password input) or
 * non-interactively via `LARADOGS_ADMIN_NAME`/`LARADOGS_ADMIN_EMAIL`/
 * `LARADOGS_ADMIN_PASSWORD` (e.g. for scripted first-boot automation) —
 * either way, nothing is ever printed back except a success/failure
 * message, never the password.
 *
 * Refuses outright if an Owner already exists (single-Owner invariant —
 * see App\Policies\UserPolicy's docblock) rather than silently doing
 * nothing or creating a second one. If an installation already has one or
 * more Admin accounts from before Owner existed (Phase 7.1.2) and needs
 * to designate one of them as Owner instead of creating a brand-new
 * account, use `laradogs:user:claim-owner` instead — see that command's
 * docblock and the `2026_09_11_000001_replace_is_admin_with_role_on_users_table`
 * migration's own docblock for the full upgrade story.
 */
final class CreateOwnerCommand extends Command
{
    use ProvisionsUserAccounts;

    protected $signature = 'laradogs:user:create-owner
        {--name= : Owner display name (prompted if omitted)}
        {--email= : Owner email (prompted if omitted)}';

    protected $description = 'Provision the LaraDogs Instance Owner (fresh installation only)';

    public function handle(): int
    {
        if (User::query()->where('role', Role::Owner)->exists()) {
            $this->components->error('An Instance Owner already exists. Use the Dashboard\'s Settings → Users (as Owner) to manage accounts, or `laradogs:user:claim-owner` to reassign ownership.');

            return self::FAILURE;
        }

        $validated = $this->promptAndValidateNewUser(
            $this->option('name') ?? config('laradogs.admin_bootstrap.name'),
            $this->option('email') ?? config('laradogs.admin_bootstrap.email'),
            config('laradogs.admin_bootstrap.password'),
        );

        if ($validated === null) {
            return self::FAILURE;
        }

        $user = $this->createUserWithRole($validated, Role::Owner);

        $this->components->info("Instance Owner created: {$user->email}");

        return self::SUCCESS;
    }
}
