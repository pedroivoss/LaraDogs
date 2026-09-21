<?php

namespace App\Audit\QualityGates\Policy;

use App\Audit\QualityGates\GateRuleId;

/**
 * `laradogs.gate.analyzer-coverage` — each listed analyzer must have
 * declared at least the required {@see CoverageRequirement} in the scan.
 * A statement about the evidence itself: coverage that is Unknown (or an
 * analyzer that did not complete) does not satisfy the requirement and is
 * a violation (Failed); "no usable execution" is Indeterminate.
 */
final readonly class AnalyzerCoverageRule implements GateRule
{
    /** @var array<string,CoverageRequirement> */
    public array $requirements;

    /**
     * @param  array<string,mixed>  $requirements  analyzer id => CoverageRequirement (untrusted input, validated here)
     */
    public function __construct(array $requirements)
    {
        if ($requirements === [] || count($requirements) > QualityGatePolicy::MAX_ANALYZERS) {
            throw new InvalidQualityGatePolicy('The analyzer-coverage rule needs between 1 and '.QualityGatePolicy::MAX_ANALYZERS.' analyzers.');
        }

        ksort($requirements);

        $validated = [];

        foreach ($requirements as $analyzer => $requirement) {
            QualityGatePolicy::assertAnalyzerId((string) $analyzer);

            if (! $requirement instanceof CoverageRequirement) {
                throw new InvalidQualityGatePolicy("Invalid coverage requirement for [{$analyzer}].");
            }

            $validated[(string) $analyzer] = $requirement;
        }

        $this->requirements = $validated;
    }

    public function id(): GateRuleId
    {
        return GateRuleId::AnalyzerCoverage;
    }

    public function toArray(): array
    {
        $requirements = [];

        foreach ($this->requirements as $analyzer => $requirement) {
            $requirements[$analyzer] = $requirement->value;
        }

        return ['type' => $this->id()->value, 'requirements' => $requirements];
    }
}
