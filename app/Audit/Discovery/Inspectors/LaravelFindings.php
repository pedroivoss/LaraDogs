<?php

namespace App\Audit\Discovery\Inspectors;

use App\Audit\Discovery\Support\Detection;
use App\Audit\Discovery\Support\VersionDetection;

final readonly class LaravelFindings
{
    /**
     * @param  array<string,Detection>  $packages
     */
    public function __construct(
        public VersionDetection $laravel,
        public Detection $blade,
        public Detection $livewire,
        public Detection $inertia,
        public array $packages,
    ) {}
}
