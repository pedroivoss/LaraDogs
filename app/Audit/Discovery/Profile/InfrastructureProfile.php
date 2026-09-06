<?php

namespace App\Audit\Discovery\Profile;

use App\Audit\Discovery\Support\Detection;
use JsonSerializable;

final readonly class InfrastructureProfile implements JsonSerializable
{
    public function __construct(
        public Detection $docker,
        public Detection $dockerCompose,
        public Detection $githubActions,
        public Detection $gitlabCi,
        public Detection $redisHints,
        public Detection $queueHints,
        public Detection $schedulerHints,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'docker' => $this->docker,
            'docker_compose' => $this->dockerCompose,
            'github_actions' => $this->githubActions,
            'gitlab_ci' => $this->gitlabCi,
            'redis_hints' => $this->redisHints,
            'queue_hints' => $this->queueHints,
            'scheduler_hints' => $this->schedulerHints,
        ];
    }
}
