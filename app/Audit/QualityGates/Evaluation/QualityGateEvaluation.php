<?php

namespace App\Audit\QualityGates\Evaluation;

use App\Audit\QualityGates\QualityGateOutcome;

/**
 * The evaluator's complete, in-memory verdict for one scan under one
 * policy — persisted verbatim by `QualityGateResultRecorder`.
 */
final readonly class QualityGateEvaluation
{
    public QualityGateOutcome $outcome;

    /**
     * @param  list<RuleResult>  $ruleResults
     */
    public function __construct(
        public array $ruleResults,
        public ?int $baselineScanId,
    ) {
        $this->outcome = QualityGateOutcome::combine(array_map(fn (RuleResult $r): QualityGateOutcome => $r->outcome, $ruleResults));
    }

    public function countWhere(QualityGateOutcome $outcome): int
    {
        return count(array_filter($this->ruleResults, fn (RuleResult $r): bool => $r->outcome === $outcome));
    }
}
