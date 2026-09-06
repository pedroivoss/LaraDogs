<?php

namespace App\Audit\Engine\Process;

/**
 * The output of a future {@see ProcessRunner} run. `stdout`/`stderr` are
 * expected to already be size-capped by whatever implements ProcessRunner
 * — this type doesn't enforce that itself, it just carries the result.
 */
final readonly class ProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public bool $timedOut,
        public int $durationMs,
    ) {}

    public function successful(): bool
    {
        return ! $this->timedOut && $this->exitCode === 0;
    }
}
