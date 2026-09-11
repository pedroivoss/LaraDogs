<?php

namespace App\Console\Commands;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Provisions LaraDogs' first administrator. Deliberately the ONLY way to
 * create the first account — see docs/self-hosting.md's security
 * principle: LaraDogs never ships/auto-creates a known default credential
 * (`admin`/`admin`, `admin@laradogs.test`/`password`, or similar). The
 * operator supplies real values, interactively (hidden password input) or
 * non-interactively via `LARADOGS_ADMIN_NAME`/`LARADOGS_ADMIN_EMAIL`/
 * `LARADOGS_ADMIN_PASSWORD` (e.g. for scripted first-boot automation) —
 * either way, nothing is ever printed back except a success/failure
 * message, never the password.
 *
 * Idempotent: running it again with an email that already exists fails
 * cleanly (no duplicate, no silent password overwrite of an existing
 * account — use the admin User Management screen to reset another user's
 * password instead).
 */
final class CreateAdminCommand extends Command
{
    use PasswordValidationRules, ProfileValidationRules;

    protected $signature = 'laradogs:user:create-admin
        {--name= : Administrator display name (prompted if omitted)}
        {--email= : Administrator email (prompted if omitted)}';

    protected $description = 'Provision the first LaraDogs administrator account';

    public function handle(): int
    {
        $name = $this->option('name') ?? config('laradogs.admin_bootstrap.name') ?? $this->ask('Administrator name');
        $email = $this->option('email') ?? config('laradogs.admin_bootstrap.email') ?? $this->ask('Administrator email');
        $bootstrapPassword = config('laradogs.admin_bootstrap.password');
        $password = $bootstrapPassword ?? $this->secret('Administrator password');
        $confirmation = $bootstrapPassword ?? $this->secret('Confirm password');

        $validator = Validator::make(
            [
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $confirmation,
            ],
            [
                ...$this->profileRules(),
                'password' => $this->passwordRules(),
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $validated = $validator->validated();

        $user = new User([
            'name' => $validated['name'],
            'email' => $validated['email'],
        ]);
        $user->password = Hash::make($validated['password']);
        $user->is_admin = true;
        $user->email_verified_at = Carbon::now();
        $user->save();

        $this->components->info("Administrator account created: {$user->email}");

        return self::SUCCESS;
    }
}
