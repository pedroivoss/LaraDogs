<?php

namespace App\Integrations\GitHub;

use App\Audit\Ci\CiOutcome;

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
     * THE canonical mapping (Phase 10.1), from the FINAL CI outcome — never
     * from the Quality Gate outcome directly, which an operational error can
     * override (a `Passed` gate with a revision mismatch is exit 3, hence
     * `failure`, never `success`).
     *
     * `Indeterminate` maps to `action_required`, not `neutral`: `neutral`
     * reads as "ran, no opinion" and some branch-protection/UI paths do not
     * visibly block on it, which would contradict the fail-closed rule that
     * Indeterminate must never look like a pass.
     */
    public static function forCiOutcome(CiOutcome $outcome): self
    {
        return match ($outcome) {
            CiOutcome::Passed => self::Success,
            CiOutcome::Failed, CiOutcome::OperationalError => self::Failure,
            CiOutcome::Indeterminate => self::ActionRequired,
            CiOutcome::NotEvaluated => self::Neutral,
        };
    }
}
