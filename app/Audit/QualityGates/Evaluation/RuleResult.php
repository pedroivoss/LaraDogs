<?php

namespace App\Audit\QualityGates\Evaluation;

use App\Audit\QualityGates\GateRuleId;
use App\Audit\QualityGates\QualityGateOutcome;

/**
 * The outcome of ONE rule for ONE subject (a severity or an analyzer).
 * Evidence is bounded on purpose: a short summary, short observed/
 * expected strings, and a capped list of finding PUBLIC ids for
 * navigation — never full findings.
 */
final readonly class RuleResult
{
    public const int MAX_FINDING_IDS = 25;

    /** @var list<string> */
    public array $findingIds;

    /**
     * @param  list<string>  $findingIds
     */
    public function __construct(
        public GateRuleId $ruleId,
        public ?string $subject,
        public QualityGateOutcome $outcome,
        public string $summary,
        public ?string $observed = null,
        public ?string $expected = null,
        public ?string $analyzerId = null,
        public ?string $severity = null,
        public int $findingCount = 0,
        array $findingIds = [],
    ) {
        $this->findingIds = array_slice($findingIds, 0, self::MAX_FINDING_IDS);
    }
}
