<?php

namespace App\Audit\QualityGates;

/**
 * The outcome of a Quality Gate (or of one of its rules) — deliberately
 * three-valued, never a boolean: `Indeterminate` means LaraDogs lacks
 * enough trustworthy evidence to assert compliance (absence of evidence
 * is NOT evidence of absence — see docs/quality-gates/README.md). It is
 * not a warning and not "unknown": it is a distinct, fail-closed result.
 *
 * Precedence when combining rule outcomes: any Failed → Failed;
 * otherwise any Indeterminate → Indeterminate; otherwise Passed.
 */
enum QualityGateOutcome: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Indeterminate = 'indeterminate';

    public function label(): string
    {
        return match ($this) {
            self::Passed => 'Passed',
            self::Failed => 'Failed',
            self::Indeterminate => 'Indeterminate',
        };
    }

    /**
     * The `laradogs:project:gate` exit code (V1 contract, see
     * docs/quality-gates/README.md#cli-exit-codes).
     */
    public function exitCode(): int
    {
        return match ($this) {
            self::Passed => 0,
            self::Failed => 1,
            self::Indeterminate => 2,
        };
    }

    /**
     * @param  list<self>  $outcomes
     */
    public static function combine(array $outcomes): self
    {
        if ($outcomes === []) {
            // No rule produced evidence of compliance — never a fabricated Pass.
            return self::Indeterminate;
        }

        if (in_array(self::Failed, $outcomes, true)) {
            return self::Failed;
        }

        return in_array(self::Indeterminate, $outcomes, true) ? self::Indeterminate : self::Passed;
    }
}
