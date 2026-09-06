<?php

namespace App\Audit\Discovery\Support;

use JsonSerializable;

/**
 * A single evidence-based detection result: a presence/absence signal plus
 * (when available) a pointer to the evidence that produced it.
 */
final readonly class Detection implements JsonSerializable
{
    private function __construct(
        public DetectionStatus $status,
        public ?string $evidence = null,
    ) {}

    public static function detected(string $evidence): self
    {
        return new self(DetectionStatus::Detected, $evidence);
    }

    public static function notDetected(): self
    {
        return new self(DetectionStatus::NotDetected);
    }

    public static function unknown(?string $reason = null): self
    {
        return new self(DetectionStatus::Unknown, $reason);
    }

    public static function invalid(string $reason): self
    {
        return new self(DetectionStatus::Invalid, $reason);
    }

    public function isDetected(): bool
    {
        return $this->status === DetectionStatus::Detected;
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'status' => $this->status->value,
            'evidence' => $this->evidence,
        ];
    }
}
