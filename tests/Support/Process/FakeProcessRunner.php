<?php

namespace Tests\Support\Process;

use App\Audit\Engine\Process\ProcessCommand;
use App\Audit\Engine\Process\ProcessResult;
use App\Audit\Engine\Process\ProcessRunner;

/**
 * A scripted {@see ProcessRunner} test double — never spawns a real
 * process. Returns one queued {@see ProcessResult} per call (in order,
 * repeating the last one once the queue is exhausted) and records every
 * {@see ProcessCommand} it was given, so a test can assert on the exact
 * argv/cwd/env/timeout a real analyzer built without needing a real
 * `composer` binary.
 */
final class FakeProcessRunner implements ProcessRunner
{
    /** @var list<ProcessResult> */
    private array $queue;

    /** @var list<ProcessCommand> */
    private array $calls = [];

    public function __construct(ProcessResult ...$results)
    {
        $this->queue = $results;
    }

    public function run(ProcessCommand $command): ProcessResult
    {
        $this->calls[] = $command;

        if (count($this->queue) > 1) {
            return array_shift($this->queue);
        }

        return $this->queue[0] ?? new ProcessResult(
            exitCode: 0,
            stdout: '',
            stderr: '',
            timedOut: false,
            outputTruncated: false,
            durationMs: 0,
        );
    }

    /**
     * @return list<ProcessCommand>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function lastCall(): ?ProcessCommand
    {
        return $this->calls[array_key_last($this->calls)] ?? null;
    }
}
