<?php

namespace App\Audit\Ci;

use App\Audit\QualityGates\QualityGateOutcome;

/**
 * The FINAL result of one `laradogs:ci:audit` run — the single, typed owner
 * of the stable exit-code contract (Phase 8's `0`/`1`/`2`/`3`/`4`, unchanged;
 * no sixth code exists). It is deliberately generic: nothing here knows about
 * GitHub. Adapters (see `App\Integrations\GitHub\GitHubCheckConclusion`) MAP
 * this outcome; they never re-derive it from the Quality Gate.
 *
 * Why it exists (Phase 10.1): the Quality Gate outcome alone is NOT the final
 * CI result. An operational condition discovered after the audit — the
 * persisted scan revision no longer matches the expected one — overrides a
 * `Passed` gate. Reporting the gate outcome to GitHub in that case would
 * publish `success` for a run whose exit code is `3`.
 */
enum CiOutcome
{
    case Passed;
    case Failed;
    case Indeterminate;
    case OperationalError;
    case NotEvaluated;

    /**
     * The one place the operational-over-gate precedence is decided.
     *
     * @param  bool  $revisionMismatched  the audited revision did not match the expected one
     * @param  QualityGateOutcome|null  $gate  null = no gate result exists for the scan (not evaluated)
     */
    public static function resolve(bool $revisionMismatched, ?QualityGateOutcome $gate): self
    {
        if ($revisionMismatched) {
            return self::OperationalError;
        }

        return match ($gate) {
            null => self::NotEvaluated,
            QualityGateOutcome::Passed => self::Passed,
            QualityGateOutcome::Failed => self::Failed,
            QualityGateOutcome::Indeterminate => self::Indeterminate,
        };
    }

    /**
     * The process exit code — must stay byte-identical to
     * `ProjectGateCommand::EXIT_*` / `QualityGateOutcome::exitCode()` (a test
     * pins the equivalence).
     */
    public function exitCode(): int
    {
        return match ($this) {
            self::Passed => 0,
            self::Failed => 1,
            self::Indeterminate => 2,
            self::OperationalError => 3,
            self::NotEvaluated => 4,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Passed => 'Passed',
            self::Failed => 'Failed',
            self::Indeterminate => 'Indeterminate',
            self::OperationalError => 'Operational error',
            self::NotEvaluated => 'Not evaluated',
        };
    }
}
