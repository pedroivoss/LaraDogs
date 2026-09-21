<?php

namespace App\Audit\QualityGates\Policy;

use App\Audit\Findings\Severity;
use App\Audit\QualityGates\GateRuleId;

/**
 * `laradogs.gate.max-open-findings` — for each ENABLED severity, the
 * number of CURRENT gate-eligible findings of exactly that severity
 * (see FindingStatus::countsTowardQualityGate()) must not exceed a
 * maximum. A severity that is absent from `limits` is not enforced; a
 * limit of 0 is an enforced "none allowed" — the two are distinct.
 * `Unknown` (unstated magnitude) is its own bucket and can be limited
 * explicitly; it is never folded into another severity.
 */
final readonly class MaxOpenFindingsRule implements GateRule
{
    public const int MAX_LIMIT = 100000;

    /** @var array<string,int> keyed by Severity value; only enabled severities */
    public array $limits;

    /**
     * @param  array<string,mixed>  $limits  untrusted input, validated here
     */
    public function __construct(array $limits)
    {
        if ($limits === []) {
            throw new InvalidQualityGatePolicy('The max-open-findings rule needs at least one enabled severity limit.');
        }

        $validated = [];

        foreach ($limits as $severity => $max) {
            if (Severity::tryFrom((string) $severity) === null) {
                throw new InvalidQualityGatePolicy("Unknown severity [{$severity}] in max-open-findings.");
            }

            if (! is_int($max) || $max < 0 || $max > self::MAX_LIMIT) {
                throw new InvalidQualityGatePolicy('Severity limits must be whole numbers between 0 and '.self::MAX_LIMIT.'.');
            }

            $validated[(string) $severity] = $max;
        }

        $this->limits = $validated;
    }

    public function id(): GateRuleId
    {
        return GateRuleId::MaxOpenFindings;
    }

    /**
     * Enabled limits in canonical (most severe first, Unknown last) order.
     *
     * @return list<array{0: Severity, 1: int}>
     */
    public function orderedLimits(): array
    {
        $ordered = [];

        foreach ([...Severity::ranked(), Severity::Unknown] as $severity) {
            if (array_key_exists($severity->value, $this->limits)) {
                $ordered[] = [$severity, $this->limits[$severity->value]];
            }
        }

        return $ordered;
    }

    public function toArray(): array
    {
        $limits = [];

        foreach ($this->orderedLimits() as [$severity, $max]) {
            $limits[$severity->value] = $max;
        }

        return ['type' => $this->id()->value, 'limits' => $limits];
    }
}
