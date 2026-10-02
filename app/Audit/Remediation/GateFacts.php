<?php

namespace App\Audit\Remediation;

/**
 * The persisted facts about the newest terminal scan's Quality Gate result
 * that decide {@see GateImpact}. `failedRules` are only the Failed rules that
 * carry a finding list, each as its (bounded) listed public ids plus the
 * true number of findings the rule counted.
 */
final readonly class GateFacts
{
    /**
     * @param  list<array{finding_ids: list<string>, finding_count: int}>  $failedRules
     */
    public function __construct(
        public string $scanPublicId,
        public string $evaluatedAt,
        public array $failedRules,
    ) {}
}
