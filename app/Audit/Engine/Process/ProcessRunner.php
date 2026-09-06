<?php

namespace App\Audit\Engine\Process;

/**
 * The boundary between the Audit Engine and real external tool execution
 * — this contract was recorded in Phase 2, before any real analyzer
 * needed it, so `Analyzer` implementations are written against it from
 * day one instead of reaching for `exec()`/`shell_exec()` directly. Its
 * first real implementation, `SymfonyProcessRunner`, and first real
 * caller, `App\Audit\Analyzers\Composer\ComposerAuditAnalyzer`, arrive in
 * Phase 4.
 *
 * A conforming implementation must, at minimum:
 * - never build a shell string (enforced by {@see ProcessCommand} having
 *   no such field, only `argv`);
 * - run in the given working directory only;
 * - pass only the given, explicit environment (no inherited secrets);
 * - enforce the given timeout and report it via `ProcessResult::$timedOut`
 *   rather than leaving the process running;
 * - cap captured stdout/stderr rather than buffering unboundedly, and
 *   report truncation via `ProcessResult::$outputTruncated`;
 * - distinguish a process that never started from any real exit code via
 *   `ProcessResult::processStartFailed()`.
 *
 * See docs/development/process-execution.md for the full reasoning and
 * ADR-0011 for the decision this contract and its implementation record.
 */
interface ProcessRunner
{
    public function run(ProcessCommand $command): ProcessResult;
}
