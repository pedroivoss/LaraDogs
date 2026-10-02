<?php

namespace App\Audit\Remediation;

use App\Models\Audit\Finding;

/**
 * The single entry point for remediation guidance (Phase 12): Dashboard, CLI
 * and MCP all call this and render the SAME {@see RemediationPlan}. Read-only:
 * it never edits a target, runs a command, calls a model or persists a plan.
 */
final class FindingRemediationService
{
    public function __construct(
        private readonly FindingRemediationQuery $query,
        private readonly RemediationPlanner $planner,
    ) {}

    public function forFinding(Finding $finding, bool $inspectCurrentSource = true): RemediationPlan
    {
        return $this->planner->plan($this->query->evidenceFor($finding, $inspectCurrentSource));
    }
}
