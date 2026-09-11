<?php

namespace App\Http\Requests\Settings\Users;

use App\Concerns\PasswordValidationRules;
use App\Http\Requests\Settings\PasswordUpdateRequest;
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
    use PasswordValidationRules;

    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
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
