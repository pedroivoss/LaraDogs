<?php

namespace App\Audit\Engine\Process;

/**
 * The future boundary between the Audit Engine and real external tool
 * execution (Phase 4+, once a real analyzer needs to shell out to
 * `composer audit`, PHPStan, Semgrep, etc.). No implementation of this
 * interface exists yet, and nothing in Phase 2 constructs or calls one —
 * it is recorded here purely as a contract, so that boundary exists in
 * code (not just in documentation) before the first real analyzer is
 * written, and so `Analyzer` implementations are written against it from
 * day one instead of reaching for `exec()`/`shell_exec()` directly.
 *
 * A conforming implementation must, at minimum:
 * - never build a shell string (enforced by {@see ProcessCommand} having
 *   no such field, only `argv`);
 * - run in the given working directory only;
 * - pass only the given, explicit environment (no inherited secrets);
 * - enforce the given timeout and report it via `ProcessResult::$timedOut`
 *   rather than leaving the process running;
 * - cap captured stdout/stderr rather than buffering unboundedly.
 *
 * See docs/auditing/audit-engine.md's security boundary section for the
 * full reasoning, and ADR-0009 for the decision this contract records.
 */
interface ProcessRunner
{
    public function run(ProcessCommand $command): ProcessResult;
}
