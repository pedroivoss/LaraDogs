<?php

namespace App\Http\Requests\Settings\Users;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
{
    use DeniesWithNotFound, ProfileValidationRules;

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
        /** @var User $target */
        $target = $this->route('user');

        return $this->profileRules($target->id);
    }
}
