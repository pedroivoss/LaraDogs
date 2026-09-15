<?php

namespace App\Audit\Projects;

use App\Audit\Discovery\DiscoveryResult;
use App\Models\Audit\Scan;

final readonly class RunProjectAuditResult
{
    private function __construct(
        public RunProjectAuditOutcome $outcome,
        public ?Scan $scan,
        public ?Scan $conflictingScan,
        public ?DiscoveryResult $discoveryFailure,
    ) {}

    public static function queued(Scan $scan): self
    {
        return new self(RunProjectAuditOutcome::Queued, $scan, null, null);
    }

    public static function completed(Scan $scan): self
    {
        return new self(RunProjectAuditOutcome::Completed, $scan, null, null);
    }

    public static function alreadyRunning(Scan $conflictingScan): self
    {
        return new self(RunProjectAuditOutcome::AlreadyRunning, null, $conflictingScan, null);
    }

    public static function pathUnavailable(DiscoveryResult $discoveryFailure): self
    {
        return new self(RunProjectAuditOutcome::PathUnavailable, null, null, $discoveryFailure);
    }

    public function succeeded(): bool
    {
        return in_array($this->outcome, [RunProjectAuditOutcome::Queued, RunProjectAuditOutcome::Completed], true);
    }
}
