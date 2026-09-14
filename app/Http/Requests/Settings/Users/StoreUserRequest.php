<?php

namespace App\Http\Requests\Settings\Users;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    use DeniesWithNotFound, PasswordValidationRules, ProfileValidationRules;

    public function authorize(): bool
    {
        return ! ($this->user()?->isUser() ?? true);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            // Accepted from any staff member, but only ever HONORED by
            // the controller when the actor passes
            // App\Policies\UserPolicy::createAdmin — an Admin's request
            // is silently downgraded to `user`, never rejected outright,
            // since "create a normal user" is still a valid action for
            // an Admin to take on the same form.
            'role' => ['required', Rule::in(['admin', 'user'])],
        ];
    }
}
