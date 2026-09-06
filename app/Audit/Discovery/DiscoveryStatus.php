<?php

namespace App\Audit\Discovery;

use App\Audit\Discovery\Profile\ProjectType;

/**
 * Whether the requested path could even be inspected. Distinct from
 * {@see ProjectType}, which classifies an
 * already-successfully-opened project directory (including a genuinely
 * empty one) — this enum only covers reasons discovery couldn't run at
 * all.
 */
enum DiscoveryStatus: string
{
    case Ok = 'ok';
    case PathNotFound = 'path_not_found';
    case PathNotDirectory = 'path_not_directory';
    case PathNotReadable = 'path_not_readable';
}
