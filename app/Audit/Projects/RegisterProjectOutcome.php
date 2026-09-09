<?php

namespace App\Audit\Projects;

use App\Audit\Discovery\DiscoveryStatus;
use App\Models\Audit\Project;

/**
 * See {@see RegisterProject} for the exact semantics behind each case.
 */
enum RegisterProjectOutcome
{
    /** A new {@see Project} row was created. */
    case Created;

    /**
     * The resolved (realpath-normalized) path was already registered —
     * registration is idempotent, not an error. The existing Project is
     * returned, never a duplicate.
     */
    case AlreadyRegistered;

    /**
     * The given path failed Discovery's own safety checks (does not
     * exist, is not a directory, is not readable) — see
     * {@see DiscoveryStatus}. No row was created.
     */
    case PathInvalid;
}
