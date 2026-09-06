<?php

namespace App\Audit\Discovery\Profile;

use App\Audit\Discovery\Support\Detection;
use App\Audit\Discovery\Support\VersionDetection;
use JsonSerializable;

final readonly class BackendProfile implements JsonSerializable
{
    /**
     * @param  array<string,Detection>  $packages  Known first-party Laravel
     *                                             packages (sanctum, fortify,
     *                                             jetstream, breeze, octane,
     *                                             horizon, telescope, pulse,
     *                                             reverb, scout, cashier),
     *                                             keyed by short name.
     */
    public function __construct(
        public VersionDetection $php,
        public Detection $composer,
        public Detection $composerLock,
        public VersionDetection $laravel,
        public Detection $blade,
        public Detection $livewire,
        public Detection $inertia,
        public array $packages = [],
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'php' => $this->php,
            'composer' => $this->composer,
            'composer_lock' => $this->composerLock,
            'laravel' => $this->laravel,
            'blade' => $this->blade,
            'livewire' => $this->livewire,
            'inertia' => $this->inertia,
            'packages' => $this->packages,
        ];
    }
}
