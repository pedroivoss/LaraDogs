<?php

namespace App\Console\Commands\Concerns;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Shared prompt/validate/create logic for `laradogs:user:create-owner`
 * and `laradogs:user:create-admin` — the only two commands that ever
 * create a brand-new account from scratch (`laradogs:user:claim-owner`
 * promotes an EXISTING account instead, so it doesn't need this). Kept
 * here rather than duplicated per command so the "never print the
 * password back, hash it exactly once, via the normal User model
 * behavior" guarantee only needs to be true in one place.
 */
trait ProvisionsUserAccounts
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * @return array{name:string,email:string,password:string}|null null
     *                                                              when validation failed — errors have already been printed.
     */
    protected function promptAndValidateNewUser(?string $name, ?string $email, ?string $bootstrapPassword): ?array
    {
        $name ??= $this->ask('Name');
        $email ??= $this->ask('Email');
        $password = $bootstrapPassword ?? $this->secret('Password');
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

            return null;
        }

        $validated = $validator->validated();

        return [
            'name' => (string) $validated['name'],
            'email' => (string) $validated['email'],
            'password' => (string) $validated['password'],
        ];
    }

    /**
     * @param  array{name:string,email:string,password:string}  $validated
     */
    protected function createUserWithRole(array $validated, Role $role): User
    {
        $user = new User([
            'name' => $validated['name'],
            'email' => $validated['email'],
        ]);
        $user->password = Hash::make($validated['password']);
        $user->role = $role;
        $user->is_active = true;
        $user->email_verified_at = Carbon::now();
        $user->save();

        return $user;
    }
}
