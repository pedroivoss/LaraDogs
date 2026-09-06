<?php

namespace App\Audit\Engine\Process;

use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The first real {@see ProcessRunner} — built on Symfony Process (already
 * a transitive dependency of `laravel/framework`; no new package added).
 *
 * Security properties, each verified against the exact vendored Symfony
 * Process source (`vendor/symfony/process/Process.php`) rather than
 * assumed — see docs/development/process-execution.md and ADR-0011:
 *
 * - **No shell.** `Process` is constructed with `$command->argv` — an
 *   array. Symfony only builds a shell string when constructed via the
 *   separate `Process::fromShellCommandline()` factory, which this class
 *   never calls.
 * - **Environment is a true allowlist, not a merge.** Symfony's `Process`
 *   ALWAYS backfills any environment key not explicitly set with the
 *   current PHP process's own inherited environment (confirmed by
 *   reading `Process::start()`/`getDefaultEnv()`) — calling `setEnv()`
 *   with just the allowlist is NOT sufficient by itself and would leak
 *   every other parent variable (DB passwords, API keys, ...). This class
 *   instead explicitly sets every OTHER inherited variable to `false` in
 *   the env array, which Symfony's own `start()` recognizes as "omit this
 *   variable from the child process" (verified at the exact line building
 *   `$envPairs`). The result is a real allowlist: nothing not explicitly
 *   named ever reaches the child process.
 * - **Timeout is real**, enforced by Symfony's own `Process::run()`,
 *   which throws `ProcessTimedOutException` — caught here and normalized
 *   into `ProcessResult::$timedOut`, never left to the caller as an
 *   exception.
 * - **Process-start failure is distinguished** from any real exit code:
 *   `ProcessStartFailedException` (thrown when `proc_open()` itself
 *   fails — verified empirically to cover a nonexistent working
 *   directory, which Symfony validates before ever spawning anything) is
 *   caught and normalized into `ProcessResult::processStartFailed()`
 *   (`exitCode === null`). A missing/non-executable BINARY is a
 *   different case, verified empirically NOT to raise this exception on
 *   this platform: `proc_open()` still succeeds, and the failed exec
 *   surfaces as an ordinary non-zero exit code (commonly 126/127) with a
 *   "command not found"-style message on stderr — so callers that need
 *   to detect a missing binary specifically (e.g. this codebase's own
 *   Composer analyzer binary resolver, which lives in the outer
 *   Analyzers namespace precisely so this Engine-layer class never has
 *   to depend on it) must check for it themselves before ever invoking a
 *   `ProcessRunner`, rather than relying on `processStartFailed()` to
 *   catch it.
 * - **Output is capped** via a streaming callback (not read after the
 *   fact), so a runaway process can't exhaust memory; truncation is
 *   reported via `ProcessResult::$outputTruncated`, never silently
 *   dropped.
 */
final class SymfonyProcessRunner implements ProcessRunner
{
    public const int DEFAULT_MAX_OUTPUT_BYTES = 5_000_000;

    public function __construct(
        private readonly int $maxOutputBytes = self::DEFAULT_MAX_OUTPUT_BYTES,
    ) {}

    public function run(ProcessCommand $command): ProcessResult
    {
        $process = new Process(
            $command->argv,
            $command->workingDirectory,
            $this->buildAllowlistedEnv($command->environment),
        );
        $process->setTimeout($command->timeoutSeconds);

        $stdout = '';
        $stderr = '';
        $truncated = false;

        $collect = function (string $type, string $data) use (&$stdout, &$stderr, &$truncated): void {
            $isOut = $type === Process::OUT;
            $buffer = $isOut ? $stdout : $stderr;

            if (strlen($buffer) >= $this->maxOutputBytes) {
                $truncated = true;

                return;
            }

            $remaining = $this->maxOutputBytes - strlen($buffer);

            if (strlen($data) > $remaining) {
                $truncated = true;
            }

            $buffer .= substr($data, 0, $remaining);

            if ($isOut) {
                $stdout = $buffer;
            } else {
                $stderr = $buffer;
            }
        };

        $clockStart = hrtime(true);

        try {
            $exitCode = $process->run($collect);
        } catch (ProcessTimedOutException) {
            return new ProcessResult(
                exitCode: null,
                stdout: $stdout,
                stderr: $stderr,
                timedOut: true,
                outputTruncated: $truncated,
                durationMs: $this->elapsedMs($clockStart),
            );
        } catch (ProcessStartFailedException $exception) {
            return new ProcessResult(
                exitCode: null,
                stdout: $stdout,
                stderr: $exception->getMessage(),
                timedOut: false,
                outputTruncated: $truncated,
                durationMs: $this->elapsedMs($clockStart),
            );
        } catch (Throwable $exception) {
            // Any other failure to start/run is normalized the same way
            // as a process-start failure, rather than letting an
            // implementation-specific exception escape this boundary.
            return new ProcessResult(
                exitCode: null,
                stdout: $stdout,
                stderr: $stderr === '' ? $exception->getMessage() : $stderr,
                timedOut: false,
                outputTruncated: $truncated,
                durationMs: $this->elapsedMs($clockStart),
            );
        }

        return new ProcessResult(
            exitCode: $exitCode,
            stdout: $stdout,
            stderr: $stderr,
            timedOut: false,
            outputTruncated: $truncated,
            durationMs: $this->elapsedMs($clockStart),
        );
    }

    private function elapsedMs(int $clockStart): int
    {
        return (int) ((hrtime(true) - $clockStart) / 1_000_000);
    }

    /**
     * Builds the full environment array actually sent to the subprocess:
     * the given allowlist, plus `false` for every OTHER variable the
     * current PHP process would otherwise leak in — see this class's own
     * docblock for why `setEnv($allowlist)` alone is not sufficient.
     *
     * @param  array<string,string>  $allowlist
     * @return array<string,string|false>
     */
    private function buildAllowlistedEnv(array $allowlist): array
    {
        $env = $allowlist;

        foreach (array_keys($this->currentProcessEnv()) as $key) {
            if (! array_key_exists($key, $env)) {
                $env[$key] = false;
            }
        }

        return $env;
    }

    /**
     * @return array<string,mixed>
     */
    private function currentProcessEnv(): array
    {
        return getenv() + $_ENV + $_SERVER;
    }
}
