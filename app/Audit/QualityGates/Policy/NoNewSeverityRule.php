<?php

namespace App\Audit\QualityGates\Policy;

use App\Audit\Findings\Severity;
use App\Audit\QualityGates\GateRuleId;

/**
 * `laradogs.gate.no-new-severity` — no NEW (or regressed) gate-eligible
 * finding at or above `minSeverity` compared with the baseline scan (the
 * previous Completed scan of the project). "New" is decided by the
 * existing Finding logical identity (fingerprint), never by titles or
 * line numbers. Unknown-severity findings count as meeting the threshold
 * (fail closed — see Severity::isAtOrAbove()).
 */
final readonly class NoNewSeverityRule implements GateRule
{
    public function __construct(public Severity $minSeverity)
    {
        if ($minSeverity->rank() === null) {
            throw new InvalidQualityGatePolicy('The minimum severity for new findings must be Critical, High, Medium, Low or Info.');
        }
    }

    public function id(): GateRuleId
    {
        return GateRuleId::NoNewSeverity;
    }

    public function toArray(): array
    {
        return ['type' => $this->id()->value, 'min_severity' => $this->minSeverity->value];
    }
}
