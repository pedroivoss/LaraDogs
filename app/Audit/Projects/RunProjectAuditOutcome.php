<?php

namespace App\Audit\Projects;

use App\Models\Audit\Scan;

/**
 * See {@see RunProjectAudit} for the exact semantics behind each case.
 */
enum RunProjectAuditOutcome
{
    /**
     * A new {@see Scan} was reserved with `Queued` status
     * (`RunProjectAudit::enqueue()`). Not yet executed — see
     * {@see RunProjectAudit::execute()} for what turns this into
     * `Completed`/`Failed`.
     */
    case Queued;

    /**
     * A new immutable {@see Scan} was recorded. This
     * means the SCAN completed — never assume the audited project is
     * "clean": inspect the scan's own analyzer executions/findings for
     * that (see docs/auditing/findings-lifecycle.md).
     */
    case Completed;

    /**
     * Refused to start: another Scan for this Project is already active
     * (`Queued` or `Running`) — see {@see RunProjectAudit}'s own docblock
     * for the portable DB-level mutex (Phase 7.1.4) that makes this a
     * real guarantee, not merely advisory.
     */
    case AlreadyRunning;

    /**
     * The Project's registered path is no longer available/valid (moved,
     * deleted, permissions changed) — re-discovered fresh at audit time,
     * never trusted from registration. No new Scan row was created (an
     * already-Queued one, if any, is marked Failed instead).
     */
    case PathUnavailable;
}
