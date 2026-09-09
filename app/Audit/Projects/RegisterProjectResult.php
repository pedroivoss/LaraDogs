<?php

namespace App\Audit\Projects;

use App\Audit\Discovery\DiscoveryResult;
use App\Models\Audit\Project;

final readonly class RegisterProjectResult
{
    private function __construct(
        public RegisterProjectOutcome $outcome,
        public ?Project $project,
        public ?DiscoveryResult $discoveryFailure,
    ) {}

    public static function created(Project $project): self
    {
        return new self(RegisterProjectOutcome::Created, $project, null);
    }

    public static function alreadyRegistered(Project $project): self
    {
        return new self(RegisterProjectOutcome::AlreadyRegistered, $project, null);
    }

    public static function pathInvalid(DiscoveryResult $discoveryFailure): self
    {
        return new self(RegisterProjectOutcome::PathInvalid, null, $discoveryFailure);
    }

    public function succeeded(): bool
    {
        return $this->outcome !== RegisterProjectOutcome::PathInvalid;
    }
}
