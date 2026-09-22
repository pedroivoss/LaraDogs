<?php

namespace App\Integrations\GitHub;

use App\Audit\QualityGates\QualityGateOutcome;

/**
 * The GitHub Checks API `conclusion` values LaraDogs actually uses — a
 * strict subset of the API's full enum (`success`, `failure`, `neutral`,
 * `cancelled`, `skipped`, `timed_out`, `action_required`; see
 * docs/integrations/github.md#outcome-mapping for the research behind this
 * mapping). `cancelled`/`timed_out`/`stale` describe states GitHub itself
 * assigns to runs it manages — never applicable to a Check Run LaraDogs
 * always creates already `completed`.
 */
enum GitHubCheckConclusion: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Neutral = 'neutral';
    case ActionRequired = 'action_required';

    /**
     * `Indeterminate` deliberately maps to `action_required`, not
     * `neutral`: GitHub's `neutral` reads as "ran, no opinion" and some
     * branch-protection/UI paths do not visibly block on it, which would
     * contradict the fail-closed rule that Indeterminate must never look
     * like a pass. `action_required` is a non-success conclusion that
     * GitHub itself describes as needing attention — never silently green.
     */
    public static function forGateOutcome(QualityGateOutcome $outcome): self
    {
        return match ($outcome) {
            QualityGateOutcome::Passed => self::Success,
            QualityGateOutcome::Failed => self::Failure,
            QualityGateOutcome::Indeterminate => self::ActionRequired,
        };
    }

    /** A gate that exists but was never evaluated (disabled) — a real "no opinion". */
    public static function notEvaluated(): self
    {
        return self::Neutral;
    }

    /** An operational failure (bad revision, audit failure, ...) — never silently green. */
    public static function operationalFailure(): self
    {
        return self::Failure;
    }
}
