<?php

namespace App\Audit\Engine;

use App\Audit\Discovery\Profile\ProjectProfile;
use App\Audit\Engine\Contracts\Analyzer;

/**
 * Everything an {@see Analyzer} needs to decide
 * applicability/availability and to run — nothing more. No HTTP request,
 * no authenticated user, no MCP client, no Eloquent model: this must stay
 * constructible and usable with zero framework bootstrap.
 *
 * Deliberately carries no timestamps — those belong to the run's *result*
 * ({@see Execution\AuditRunResult}), not its inputs, so there's only ever
 * one place a "when did this happen" question can be answered from.
 */
final readonly class AuditContext
{
    public function __construct(
        public string $runId,
        public string $projectPath,
        public ProjectProfile $profile,
        public AuditExecutionSettings $settings = new AuditExecutionSettings,
    ) {}
}
