<?php

namespace App\Audit\Projects;

use App\Audit\Discovery\ProjectDiscovery;

/**
 * One directory LaraDogs found directly under the configured project root
 * (see {@see ProjectDirectoryDiscovery}), already realpath-resolved and
 * containment-checked. `looksLikeLaravel` is a cheap, execution-free signal
 * (file existence only — never opened, parsed, or run) meant purely to help
 * a human pick the right directory in the Dashboard's picker; it is NOT a
 * substitute for {@see ProjectDiscovery}'s own real
 * inspection, which still runs at registration/audit time.
 */
final readonly class ProjectDirectoryCandidate
{
    public function __construct(
        public string $name,
        public string $path,
        public bool $alreadyRegistered,
        public bool $looksLikeLaravel,
    ) {}
}
