<?php

namespace App\Audit\QualityGates\Evaluation;

/**
 * Everything the {@see QualityGateEvaluator} needs, already loaded from
 * persisted data — so the evaluator itself is a pure function with no
 * database, filesystem, network or clock access.
 */
final readonly class GateEvidence
{
    /**
     * @param  bool  $scanCompleted  the evaluated scan reached Completed (false: Failed/incomplete)
     * @param  array<string,ExecutionEvidence>  $executions  this scan's executions keyed by analyzer id
     * @param  array<string,int>  $openCountsBySeverity  CURRENT gate-eligible findings per severity value
     * @param  array<string,list<string>>  $openFindingIdsBySeverity  bounded public ids per severity value
     * @param  int|null  $baselineScanId  the previous Completed scan, or null when none exists
     * @param  array<string,ExecutionEvidence>  $baselineExecutions  baseline executions keyed by analyzer id
     * @param  list<NewFindingCandidate>  $newCandidates  only populated when a no-new rule and a baseline exist
     */
    public function __construct(
        public bool $scanCompleted,
        public array $executions,
        public array $openCountsBySeverity,
        public array $openFindingIdsBySeverity,
        public ?int $baselineScanId,
        public array $baselineExecutions,
        public array $newCandidates,
    ) {}
}
