<?php

namespace App\Http\Requests;

use App\Audit\Findings\FindingStatus;
use App\Audit\Findings\Lifecycle\FindingLifecycleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a Dashboard-triggered finding status transition. This is
 * intentionally shallow validation only (well-formed input) — the actual
 * transition RULES (which statuses require a reason, what's a valid
 * status value) are enforced by
 * {@see FindingLifecycleService} itself, not
 * duplicated here. Server-side reason enforcement happens in the domain
 * service regardless of what this request allows through, so a client
 * that skips its own validation still can't bypass it.
 */
final class UpdateFindingStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(FindingStatus::class)],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function status(): FindingStatus
    {
        return FindingStatus::from($this->validated('status'));
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) && trim($reason) !== '' ? $reason : null;
    }
}
