<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ProvisionsUserAccounts;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Provisions an Admin account (Phase 7.1.3 semantics — see this class's
 * own history: Phase 7.1.2 originally used this command to create the
 * FIRST privileged account at all; now that Owner exists, that job
 * belongs to `laradogs:user:create-owner` instead, and this command
 * REQUIRES an Owner to already exist before it will do anything).
 *
 * This is a deliberate, documented semantics change, not a silent one:
 * running this command on an installation with no Owner yet fails
 * cleanly with a message pointing at `create-owner`, rather than
 * creating an ambiguously-privileged account or silently becoming a
 * second bootstrap path. An Owner can equally create an Admin from the
 * Dashboard's Settings → Users — this command exists for CLI-only/
 * scripted provisioning, not because the Dashboard can't do it.
 */
final class CreateAdminCommand extends Command
{
    use ProvisionsUserAccounts;

    protected $signature = 'laradogs:user:create-admin
        {--name= : Administrator display name (prompted if omitted)}
        {--email= : Administrator email (prompted if omitted)}';

    protected $description = 'Provision an Admin account (requires an Instance Owner to already exist)';

    public function handle(): int
    {
        if (! User::query()->where('role', Role::Owner)->exists()) {
            $this->components->error('No Instance Owner exists yet. Run `laradogs:user:create-owner` first — the Owner can then create Admin accounts, from this command or the Dashboard\'s Settings → Users.');

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

        $user = $this->createUserWithRole($validated, Role::Admin);

        $this->components->info("Admin account created: {$user->email}");

        return self::SUCCESS;
    }
}
