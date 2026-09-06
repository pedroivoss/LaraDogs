<?php

namespace App\Audit\Discovery\Profile;

use App\Audit\Discovery\Support\Detection;
use JsonSerializable;

final readonly class FrontendProfile implements JsonSerializable
{
    public function __construct(
        public Detection $node,
        public ?PackageManager $packageManager,
        public Detection $vite,
        public Detection $react,
        public Detection $vue,
        public Detection $typescript,
        public Detection $tailwind,
        public Detection $inertiaClient,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'node' => $this->node,
            'package_manager' => $this->packageManager?->value,
            'vite' => $this->vite,
            'react' => $this->react,
            'vue' => $this->vue,
            'typescript' => $this->typescript,
            'tailwind' => $this->tailwind,
            'inertia_client' => $this->inertiaClient,
        ];
    }
}
