<?php

namespace App\Audit\Discovery\Profile;

use App\Audit\Discovery\Support\Detection;
use App\Audit\Discovery\Support\DiscoveryIssue;
use App\Audit\Discovery\Support\VersionDetection;
use JsonSerializable;

/**
 * A normalized snapshot of DETECTED capabilities for a single project
 * directory. Every leaf value is a {@see Detection}
 * or {@see VersionDetection} carrying its own
 * status — this object never asserts a capability as fact beyond what its
 * inspectors actually found evidence for.
 */
final readonly class ProjectProfile implements JsonSerializable
{
    /**
     * @param  list<DiscoveryIssue>  $issues
     */
    public function __construct(
        public string $path,
        public ProjectType $type,
        public BackendProfile $backend,
        public FrontendProfile $frontend,
        public TestingProfile $testing,
        public InfrastructureProfile $infrastructure,
        public DatabaseProfile $database,
        public array $issues = [],
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'project' => [
                'path' => $this->path,
                'type' => $this->type->value,
            ],
            'backend' => $this->backend,
            'frontend' => $this->frontend,
            'testing' => $this->testing,
            'infrastructure' => $this->infrastructure,
            'database' => $this->database,
            'issues' => $this->issues,
        ];
    }
}
