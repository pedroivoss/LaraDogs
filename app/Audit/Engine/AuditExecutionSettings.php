<?php

namespace App\Audit\Engine;

use App\Audit\Engine\Process\ProcessRunner;
use JsonSerializable;

/**
 * Execution policy for one audit run.
 *
 * `defaultTimeoutSeconds` is a documented budget threaded through to
 * analyzers/the future {@see ProcessRunner} —
 * Phase 2 does not enforce it itself (see docs/auditing/audit-engine.md
 * for why: without a real subprocess, there is nothing to preempt).
 * Per-analyzer timeout overrides and richer fail-fast policies (e.g. stop
 * after N failures) are future work; today `continueOnFailure` is a
 * simple global switch.
 */
final readonly class AuditExecutionSettings implements JsonSerializable
{
    public function __construct(
        public bool $continueOnFailure = true,
        public int $defaultTimeoutSeconds = 30,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'continue_on_failure' => $this->continueOnFailure,
            'default_timeout_seconds' => $this->defaultTimeoutSeconds,
        ];
    }
}
