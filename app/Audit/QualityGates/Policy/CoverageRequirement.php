<?php

namespace App\Audit\QualityGates\Policy;

use App\Audit\Engine\Execution\CoverageMode;

/**
 * Minimum analyzer coverage a policy may demand. Not a numeric scale:
 * `Explicit` (the analyzer declared exactly which rules it verified) and
 * `Full` (it declared its whole domain covered) are different kinds of
 * claim, so V1 offers two named requirements only. `Unknown` is never a
 * requirement (it is the absence of evidence).
 *
 * Only Semgrep declares Explicit today; composer-audit / npm-audit always
 * report Unknown, and no analyzer reports Full — a policy demanding
 * either from them fails by design rather than pretending otherwise.
 */
enum CoverageRequirement: string
{
    case ExplicitOrFull = 'explicit_or_full';
    case Full = 'full';

    public function isSatisfiedBy(CoverageMode $mode): bool
    {
        return match ($this) {
            self::ExplicitOrFull => $mode === CoverageMode::Explicit || $mode === CoverageMode::Full,
            self::Full => $mode === CoverageMode::Full,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ExplicitOrFull => 'explicit or full',
            self::Full => 'full',
        };
    }
}
