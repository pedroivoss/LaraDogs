<?php

namespace App\Audit\QualityGates\Policy;

use App\Audit\QualityGates\GateRuleId;

/**
 * One typed, declarative rule of a {@see QualityGatePolicy}. Rules are
 * DATA — thresholds and identifiers — never code: nothing here is ever
 * evaluated as an expression, SQL fragment or shell string.
 */
interface GateRule
{
    public function id(): GateRuleId;

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array;
}
