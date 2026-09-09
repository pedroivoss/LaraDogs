<?php

namespace App\Audit\Projects;

use App\Models\Audit\Scan;

/**
 * See {@see RunProjectAudit} for the exact semantics behind each case.
 */
enum RunProjectAuditOutcome
{
    /**
     * A new immutable {@see Scan} was recorded. This
     * means the SCAN completed — never assume the audited project is
     * "clean": inspect the scan's own analyzer executions/findings for
     * that (see docs/auditing/findings-lifecycle.md).
     */
    case Completed;

    /**
     * Refused to start: another Scan for this Project is already
     * `Running`. See {@see RunProjectAudit}'s own docblock for why this is
     * an advisory, non-locking guard, not distributed locking.
     */
    case AlreadyRunning;

    /**
     * The Project's registered path is no longer available/valid (moved,
     * deleted, permissions changed) — re-discovered fresh at audit time,
     * never trusted from registration. No Scan row was created.
     */
    case PathUnavailable;
}
