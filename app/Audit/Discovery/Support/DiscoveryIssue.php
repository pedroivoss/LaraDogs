<?php

namespace App\Audit\Discovery\Support;

use JsonSerializable;

/**
 * A non-fatal problem noticed while inspecting a specific evidence source
 * (e.g. a malformed manifest), recorded so callers can see *why* related
 * detections came back Invalid/Unknown instead of silently guessing.
 */
final readonly class DiscoveryIssue implements JsonSerializable
{
    public function __construct(
        public string $source,
        public string $message,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'source' => $this->source,
            'message' => $this->message,
        ];
    }
}
