<?php

namespace App\Audit\Discovery\Support;

use JsonSerializable;

/**
 * A version-aware detection result, distinguishing a declared constraint
 * (e.g. composer.json's "^13.0") from an actually-installed version
 * resolved from a lock file (e.g. composer.lock's "13.4.2"). Never invents
 * an installed version from a constraint alone.
 */
final readonly class VersionDetection implements JsonSerializable
{
    private function __construct(
        public DetectionStatus $status,
        public ?string $constraint = null,
        public ?string $installedVersion = null,
        public ?string $evidence = null,
    ) {}

    public static function fromInstalledVersion(string $installedVersion, string $evidence, ?string $constraint = null): self
    {
        return new self(DetectionStatus::Detected, $constraint, $installedVersion, $evidence);
    }

    public static function fromConstraint(string $constraint, string $evidence): self
    {
        return new self(DetectionStatus::Detected, $constraint, null, $evidence);
    }

    public static function notDetected(): self
    {
        return new self(DetectionStatus::NotDetected);
    }

    public static function unknown(?string $reason = null): self
    {
        return new self(DetectionStatus::Unknown, evidence: $reason);
    }

    public static function invalid(string $reason): self
    {
        return new self(DetectionStatus::Invalid, evidence: $reason);
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
            'constraint' => $this->constraint,
            'installed_version' => $this->installedVersion,
            'evidence' => $this->evidence,
        ];
    }
}
