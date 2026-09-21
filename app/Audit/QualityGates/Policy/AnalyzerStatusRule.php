<?php

namespace App\Audit\QualityGates\Policy;

use App\Audit\QualityGates\GateRuleId;

/**
 * `laradogs.gate.analyzer-status` — each listed analyzer must have
 * executed with status Passed in the scan. This is a statement about the
 * execution itself: Failed / TimedOut / Unavailable is a violation
 * (Failed), while "no usable execution" (missing, NotApplicable, Skipped)
 * is Indeterminate.
 */
final readonly class AnalyzerStatusRule implements GateRule
{
    /** @var list<string> */
    public array $analyzers;

    /**
     * @param  list<string>  $analyzers
     */
    public function __construct(array $analyzers)
    {
        $analyzers = array_values(array_unique($analyzers));
        sort($analyzers);

        if ($analyzers === [] || count($analyzers) > QualityGatePolicy::MAX_ANALYZERS) {
            throw new InvalidQualityGatePolicy('The analyzer-status rule needs between 1 and '.QualityGatePolicy::MAX_ANALYZERS.' analyzers.');
        }

        foreach ($analyzers as $analyzer) {
            QualityGatePolicy::assertAnalyzerId($analyzer);
        }

        $this->analyzers = $analyzers;
    }

    public function id(): GateRuleId
    {
        return GateRuleId::AnalyzerStatus;
    }

    public function toArray(): array
    {
        return ['type' => $this->id()->value, 'analyzers' => $this->analyzers];
    }
}
