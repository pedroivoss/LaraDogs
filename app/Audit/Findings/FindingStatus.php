<?php

namespace App\Audit\Findings;

use App\Audit\Findings\Ingestion\FindingReconciler;
use App\Audit\Findings\Lifecycle\FindingLifecycleService;

/**
 * A finding's lifecycle status — see docs/auditing/findings-lifecycle.md
 * for the full transition rules. `REGRESSED` is deliberately NOT a status
 * here: a resolved finding reappearing transitions back to `Open`, and the
 * regression itself is recorded as an event in `finding_status_histories`
 * (inferable from `previous_status = Resolved` + `new_status = Open`), not
 * as a status a finding can sit in indefinitely.
 */
enum FindingStatus: string
{
    /** Default state; detected and not yet triaged. */
    case Open = 'open';

    /** A human has verified this is a real issue. */
    case Confirmed = 'confirmed';

    /** No longer detected by a reliably-executed analyzer. Never asserted by an analyzer directly — only by reconciliation. See {@see FindingReconciler}. */
    case Resolved = 'resolved';

    /** Real, but knowingly not being fixed. Requires a reason. */
    case AcceptedRisk = 'accepted_risk';

    /** Not a real issue; the detection was wrong. Requires a reason. */
    case FalsePositive = 'false_positive';

    /** Deliberately excluded from attention without asserting it's wrong. Requires a reason. */
    case Ignored = 'ignored';

    /**
     * Whether transitioning a finding INTO this status requires a
     * human-readable reason to be recorded — see
     * {@see FindingLifecycleService}.
     */
    public function requiresReason(): bool
    {
        return match ($this) {
            self::AcceptedRisk, self::FalsePositive, self::Ignored => true,
            default => false,
        };
    }

    /**
     * Suppressed statuses are deliberate human (or automated policy, in
     * the future) decisions to stop caring about a finding. Re-observing
     * a suppressed finding in a later scan must NOT automatically flip its
     * status back — that would defeat the purpose of suppressing it.
     */
    public function isSuppressed(): bool
    {
        return match ($this) {
            self::AcceptedRisk, self::FalsePositive, self::Ignored => true,
            default => false,
        };
    }
}
