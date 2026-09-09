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

    /**
     * A human-readable description of a FAILED status, given the path
     * that was requested. Shared by every adapter that surfaces a
     * {@see DiscoveryResult} failure (CLI commands today) so the wording
     * only needs to be maintained in one place.
     */
    public function describe(string $path): string
    {
        return match ($this) {
            self::PathNotFound => "Path not found: {$path}",
            self::PathNotDirectory => "Not a directory: {$path}",
            self::PathNotReadable => "Path is not readable: {$path}",
            self::Ok => 'Unexpected: reported as failure but status is ok.',
        };
    }
}
