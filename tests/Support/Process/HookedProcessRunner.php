<?php

namespace Tests\Support\Process;

use App\Audit\Engine\Process\ProcessCommand;
use App\Audit\Engine\Process\ProcessResult;
use App\Audit\Engine\Process\ProcessRunner;
use Closure;

/**
 * Delegates to a real runner and lets a test act at a DETERMINISTIC point of
 * the orchestration: `$beforeInspection(n)` runs just before the n-th
 * (1-based) Git inspection starts — a Git inspection begins with
 * `rev-parse --is-bare-repository`. No timing, no race: e.g. "inspection 1
 * is the CI pre-check, inspection 2 is the audit's own before-snapshot".
 */
final class HookedProcessRunner implements ProcessRunner
{
    private int $inspections = 0;

    /**
     * @param  Closure(int): void  $beforeInspection
     */
    public function __construct(private readonly ProcessRunner $inner, private readonly Closure $beforeInspection) {}

    public function run(ProcessCommand $command): ProcessResult
    {
        if (in_array('--is-bare-repository', $command->argv, true)) {
            $this->inspections++;
            ($this->beforeInspection)($this->inspections);
        }

        return $this->inner->run($command);
    }
}
