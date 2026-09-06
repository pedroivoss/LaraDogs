<?php

namespace App\Audit\Engine\Contracts;

use JsonSerializable;

/**
 * Whether an analyzer's rules make sense for THIS project's detected
 * stack (e.g. an npm-based analyzer has nothing to do in a project with no
 * package.json) — a fact about the project, independent of whether the
 * analyzer's tooling happens to be installed on this host. See
 * {@see Availability} for that second, separate question.
 */
final readonly class Applicability implements JsonSerializable
{
    private function __construct(
        public ApplicabilityStatus $status,
        public ?string $reason = null,
    ) {}

    public static function applicable(?string $reason = null): self
    {
        return new self(ApplicabilityStatus::Applicable, $reason);
    }

    public static function notApplicable(string $reason): self
    {
        return new self(ApplicabilityStatus::NotApplicable, $reason);
    }

    public function isApplicable(): bool
    {
        return $this->status === ApplicabilityStatus::Applicable;
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'status' => $this->status->value,
            'reason' => $this->reason,
        ];
    }
}
