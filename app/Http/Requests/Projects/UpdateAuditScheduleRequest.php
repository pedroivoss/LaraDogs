<?php

namespace App\Http\Requests\Projects;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAuditScheduleRequest extends FormRequest
{
    /**
     * Authorization itself is the `staff` route middleware
     * (Owner/Admin) — this stays `true` as defense in depth would be
     * redundant here since the middleware already 404s a User before
     * this request class is even resolved for this route.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'audit_schedule' => ['required', Rule::in(['disabled', 'daily', 'weekly', 'monthly'])],
            'audit_schedule_day_of_week' => ['required_if:audit_schedule,weekly', 'nullable', 'integer', 'between:0,6'],
            'audit_schedule_day_of_month' => ['required_if:audit_schedule,monthly', 'nullable', 'integer', 'between:1,31'],
        ];
    }
}
