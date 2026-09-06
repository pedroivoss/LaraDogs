<?php

namespace App\Audit\Discovery\Inspectors;

use App\Audit\Discovery\Support\Detection;
use App\Audit\Discovery\Support\VersionDetection;

final readonly class ComposerFindings
{
    public function __construct(
        public VersionDetection $php,
        public Detection $composer,
        public Detection $composerLock,
    ) {}
}
