<?php

namespace App\Audit\Engine\Contracts;

use JsonSerializable;

/**
 * Whether an analyzer's tooling can actually run on THIS host right now
 * (e.g. is the `composer` binary present) — independent of
 * {@see Applicability}, which is about the project, not the environment.
 * An analyzer can be applicable but unavailable, or (today, since no real
 * analyzer exists yet) available but not applicable.
 */
final readonly class Availability implements JsonSerializable
{
    private function __construct(
        public AvailabilityStatus $status,
        public ?string $reason = null,
    ) {}

    public static function available(?string $reason = null): self
    {
        return new self(AvailabilityStatus::Available, $reason);
    }

    public static function unavailable(string $reason): self
    {
        return new self(AvailabilityStatus::Unavailable, $reason);
    }

    public function isAvailable(): bool
    {
        return $this->status === AvailabilityStatus::Available;
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
