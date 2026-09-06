<?php

namespace App\Audit\Discovery;

use App\Audit\Discovery\Profile\ProjectProfile;
use JsonSerializable;

final readonly class DiscoveryResult implements JsonSerializable
{
    private function __construct(
        public string $path,
        public DiscoveryStatus $status,
        public ?ProjectProfile $profile,
    ) {}

    public static function ok(string $path, ProjectProfile $profile): self
    {
        return new self($path, DiscoveryStatus::Ok, $profile);
    }

    public static function failed(string $path, DiscoveryStatus $status): self
    {
        return new self($path, $status, null);
    }

    public function isSuccessful(): bool
    {
        return $this->status === DiscoveryStatus::Ok;
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'path' => $this->path,
            'status' => $this->status->value,
            'profile' => $this->profile,
        ];
    }
}
