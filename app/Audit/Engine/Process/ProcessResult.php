<?php

namespace App\Audit\Engine\Process;

/**
 * The output of a {@see ProcessRunner} run. `stdout`/`stderr` are already
 * size-capped by whatever implements ProcessRunner — this type doesn't
 * enforce that itself, it just carries the result and a flag saying
 * whether capping actually discarded data.
 *
 * `exitCode` is nullable and specifically distinguishes "the process
 * never started at the `proc_open()` level" (verified empirically to
 * cover e.g. a nonexistent working directory) from any real exit code,
 * including `0` — a caller must never conflate the two (see
 * {@see processStartFailed()}). Note a MISSING BINARY is not covered by
 * this: verified empirically to still produce a real (non-zero) exit
 * code rather than a start failure — see
 * `App\Audit\Engine\Process\SymfonyProcessRunner`'s own docblock.
 */
final readonly class ProcessResult
{
    public function __construct(
        public ?int $exitCode,
        public string $stdout,
        public string $stderr,
        public bool $timedOut,
        public bool $outputTruncated,
        public int $durationMs,
    ) {}

    public function successful(): bool
    {
        return ! $this->timedOut && $this->exitCode === 0;
    }

    /**
     * True only when the process never actually started (e.g. the
     * executable was missing or not runnable) — never true for a timeout,
     * which has its own, more specific flag.
     */
    public function processStartFailed(): bool
    {
        return ! $this->timedOut && $this->exitCode === null;
    }
}
