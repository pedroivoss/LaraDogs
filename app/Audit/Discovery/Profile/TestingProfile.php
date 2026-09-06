<?php

namespace App\Audit\Discovery\Profile;

use App\Audit\Discovery\Support\Detection;
use JsonSerializable;

final readonly class TestingProfile implements JsonSerializable
{
    public function __construct(
        public Detection $pest,
        public Detection $phpunit,
        public Detection $playwright,
        public Detection $vitest,
        public Detection $jest,
        public Detection $cypress,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'pest' => $this->pest,
            'phpunit' => $this->phpunit,
            'playwright' => $this->playwright,
            'vitest' => $this->vitest,
            'jest' => $this->jest,
            'cypress' => $this->cypress,
        ];
    }
}
