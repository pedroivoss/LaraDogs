<?php

namespace App\Audit\Projects;

/**
 * @see ProjectDirectoryDiscovery
 */
final readonly class ProjectDirectoryListResult
{
    /**
     * @param  list<ProjectDirectoryCandidate>  $candidates
     */
    private function __construct(
        public bool $rootAvailable,
        public array $candidates,
    ) {}

    public static function unavailable(): self
    {
        return new self(rootAvailable: false, candidates: []);
    }

    /**
     * @param  list<ProjectDirectoryCandidate>  $candidates
     */
    public static function ok(array $candidates): self
    {
        return new self(rootAvailable: true, candidates: $candidates);
    }
}
