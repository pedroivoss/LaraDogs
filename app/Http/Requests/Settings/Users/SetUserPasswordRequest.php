<?php

namespace App\Http\Requests\Settings\Users;

use App\Concerns\PasswordValidationRules;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An ADMIN setting another user's password — deliberately no
 * `current_password` rule (unlike {@see PasswordUpdateRequest},
 * which is a user changing their OWN password). The admin never sees or
 * needs the target user's existing password; it stays hashed and
 * unreadable either way.
 */
class SetUserPasswordRequest extends FormRequest
{
    use DeniesWithNotFound, PasswordValidationRules;

    public function authorize(): bool
    {
        /** @var User|null $target */
        $target = $this->route('user');

        return $target !== null && (bool) $this->user()?->can('manage', $target);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => $this->passwordRules(),
        ];
    }
}
