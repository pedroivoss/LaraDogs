# Process Execution

**Status: Implemented (Phase 4).** `App\Audit\Engine\Process\ProcessRunner`
is the boundary every real analyzer must use to shell out to an external
tool — recorded as a contract in Phase 2, implemented here for the first
time by `SymfonyProcessRunner`, and reused unchanged by all three real
analyzers since (`composer-audit`, Phase 4; `npm-audit`, Phase 4.2;
`semgrep`, Phase 5 — see
[`../auditing/analyzers/semgrep.md`](../auditing/analyzers/semgrep.md)).
See
[ADR-0011](../architecture/decisions/ADR-0011-safe-external-process-execution.md)
for the decision behind this design, and
[`../auditing/analyzers/composer-audit.md`](../auditing/analyzers/composer-audit.md)
for its first real caller.

**A related but distinct trust boundary (Phase 4.2.1):** this contract's
`environment` allowlist controls what LaraDogs' OWN process environment
can leak into a child process — it says nothing about what the CHILD
PROCESS's own config-file mechanism (e.g. npm's `.npmrc`, read from the
target's `workingDirectory`) might independently do once it's running,
such as routing its own network calls through a proxy the target
configured. `App\Audit\Analyzers\Npm\NpmAuditAnalyzer` closes that
specific gap with explicit CLI flags rather than anything in
`ProcessRunner` itself — see
[`../auditing/analyzers/npm-audit.md`](../auditing/analyzers/npm-audit.md#9-npm-configuration-security)
for the full research and
[ADR-0011's amendment](../architecture/decisions/ADR-0011-safe-external-process-execution.md)
for why this stayed a per-analyzer concern rather than becoming a new
`ProcessRunner`-level primitive.

## Why this exists

An analyzer that needs to run `composer audit`, `npm audit`, or any other
external tool is, by construction, running a subprocess against — or at
least inside the directory of — an **untrusted, analyzed project**. Every
property below exists to make that safe:

- the analyzed project must never be able to inject an unintended command
  (no shell string ever exists to inject into);
- the analyzed project's own scripts/plugins must never run as a side
  effect of being audited (that's each analyzer's own job to prevent via
  its argv choices — e.g. Composer's `--no-plugins --no-scripts` — but
  `ProcessRunner` itself never runs a shell that could reinterpret
  anything);
- LaraDogs' own secrets (`DB_PASSWORD`, API keys, MCP credentials, CI
  tokens, ...) must never leak into a child process just because that
  process happened to inherit the parent's environment;
- a runaway or hanging tool must not hang LaraDogs itself, or exhaust its
  memory buffering unbounded output.

## The contract

```php
interface ProcessRunner
{
    public function run(ProcessCommand $command): ProcessResult;
}
```

`ProcessCommand` (`app/Audit/Engine/Process/ProcessCommand.php`):

```php
final readonly class ProcessCommand
{
    public function __construct(
        public array $argv,
        public string $workingDirectory,
        public array $environment = [],
        public int $timeoutSeconds = 30,
    ) {}
}
```

There is deliberately **no shell-string field** — `argv` is the only way
to specify a command, so there is nothing for a caller to interpolate
untrusted input into in the first place. `environment` is an explicit
allowlist the caller builds itself (see below) — never "whatever this
process inherited."

`ProcessResult`:

```php
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

    public function successful(): bool; // !timedOut && exitCode === 0
    public function processStartFailed(): bool; // !timedOut && exitCode === null
}
```

`exitCode` is nullable specifically so "the process never started at the
`proc_open()` level" is distinguishable from any real exit code,
including `0`.

## `SymfonyProcessRunner`

The first (and, as of Phase 4, only) implementation, built on
[Symfony Process](https://symfony.com/doc/current/components/process.html)
— already a transitive dependency of `laravel/framework` (verified via
`composer show symfony/process` before writing this; no new Composer
package was added). Every property below was verified against the exact
vendored Symfony Process source (`vendor/symfony/process/Process.php`),
not assumed from general familiarity with the library.

### No shell

`Process` is constructed with `$command->argv` — a plain array. Symfony
only builds a shell string when constructed via the separate
`Process::fromShellCommandline()` factory, which `SymfonyProcessRunner`
never calls. A literal shell metacharacter passed as one argv element
(`; touch SHOULD_NEVER_EXIST`, `` `whoami` ``, `$(id)`, `&& rm -rf /`) is
delivered to the child process as one inert, literal argument string —
proven by
`tests/Unit/Audit/Engine/Process/SymfonyProcessRunnerTest.php`'s
"passes argv through verbatim, never through a shell" test, which passes
all four of those strings as arguments to a fixture script and asserts
they're echoed back byte-for-byte with no side effect.

### Environment: a true allowlist, not a merge

This was the least obvious part of this implementation, and the reason
it's called out explicitly rather than assumed correct: Symfony's
`Process` **always backfills any environment key not explicitly set**
with the current PHP process's own inherited environment (confirmed by
reading `Process::start()`/`getDefaultEnv()` — `getDefaultEnv()` reads
`getenv()`/`$_ENV`/`$_SERVER` directly). Calling `setEnv($allowlist)` with
just the allowlist is **not sufficient by itself** — it would still leak
every other parent variable Symfony backfills in underneath it, including
`DB_PASSWORD`, LaraDogs' own API keys, and MCP/CI credentials.

The mitigation, verified by reading `Process::start()`'s exact
`$envPairs` construction: Symfony treats an environment entry whose value
is boolean `false` as "omit this variable from the child process." So
`SymfonyProcessRunner::buildAllowlistedEnv()` builds the _full_ env array
sent to Symfony as: the given allowlist (real values) **plus** every
other variable the current PHP process would otherwise inherit,
explicitly set to `false`. The result is a real allowlist — nothing not
explicitly named ever reaches the child process. Proven by two tests:
one that leaks a sentinel var via `putenv()` and asserts it does NOT
reach the child unless allowlisted, one that asserts an allowlisted
variable DOES reach the child.

`ComposerAuditAnalyzer` builds its own allowlist from
`config('laradogs.process.env_allowlist')` — `PATH`, `HOME`,
`COMPOSER_HOME`, `COMPOSER_CACHE_DIR`, `SSL_CERT_*`, and the common proxy
variables — read from **LaraDogs' own environment**, never the target's
`.env` (which LaraDogs never reads in the first place). This is exactly
why the official Docker image (Phase 4.1) needed zero analyzer code
changes to give Composer a controlled, outside-the-target cache
directory: it just sets `COMPOSER_HOME` as a container-level `ENV`,
which this existing allowlist mechanism forwards automatically. See
[`docker.md`](docker.md#composer-in-the-runtime-image) and
[`../auditing/analyzers/composer-audit.md`](../auditing/analyzers/composer-audit.md#docker-impact)
for the full account, including a real, read-only-mounted-target
verification.

`SemgrepAnalyzer` (Phase 5) follows the identical pattern: it forces
`SEMGREP_SETTINGS_FILE` (never merely forwarded from the allowlist) to a
LaraDogs-controlled path, and never adds Semgrep's own login/API-token
variable (`SEMGREP_APP_TOKEN`) to the allowlist at all — see
[`../auditing/analyzers/semgrep.md`](../auditing/analyzers/semgrep.md#telemetry--network)
for the full research, and
[`docker.md`](docker.md#semgrep-in-the-runtime-image) for its Docker
packaging.

### Timeout

`$process->setTimeout($command->timeoutSeconds)`; Symfony's own
`Process::run()` throws `ProcessTimedOutException` when it's exceeded.
`SymfonyProcessRunner` catches it and normalizes it into
`ProcessResult::$timedOut = true`, never letting the exception escape to
the caller. Verified with a real fixture script that sleeps for longer
than the configured timeout.

### Process-start failure vs. a missing executable — verified empirically, not assumed

`ProcessStartFailedException` (thrown when `proc_open()` itself fails) is
caught and normalized into `ProcessResult::processStartFailed()`
(`exitCode === null`). Verified empirically (not merely read from docs)
to cover a **nonexistent working directory** — Symfony validates `cwd`
itself before ever spawning anything, and throws with a clear message.

A **missing/non-executable binary** is a _different_ case, and was
initially assumed (incorrectly) to also raise this exception — actual
behavior, verified by running it: `proc_open()` still succeeds on this
platform, and the failed exec surfaces as an ordinary non-zero exit code
(commonly 126/127) with a "command not found"-style message on stderr,
not a start-failure. This is why `ComposerBinaryResolver` checks
`is_file()`/`is_executable()` itself _before_ ever invoking a
`ProcessRunner` — a caller that needs to detect a missing binary
specifically must do so on its own, rather than relying on
`processStartFailed()` to catch it. Both behaviors are covered by
dedicated tests in `SymfonyProcessRunnerTest.php`.

### Output capping

Captured via Symfony's streaming callback (`Process::OUT`/`Process::ERR`),
not by reading the full buffer after the process exits — so a runaway
process can't exhaust memory. `SymfonyProcessRunner::$maxOutputBytes`
(default `SymfonyProcessRunner::DEFAULT_MAX_OUTPUT_BYTES`, 5,000,000, also
configurable via `config('laradogs.process.max_output_bytes')`) caps each
stream independently; once either buffer would exceed the cap, further
bytes for that stream are dropped and `ProcessResult::$outputTruncated`
is set to `true` — a distinct signal from any other failure mode, so a
caller can refuse to trust truncated output (as `ComposerAuditAnalyzer`
does) rather than silently parsing a partial response as if it were
complete.

## Testing philosophy: real process, LaraDogs' own fixtures only

`SymfonyProcessRunnerTest.php` exercises the REAL implementation — no
mocking of Symfony's `Process` — but only ever against small PHP scripts
LaraDogs itself controls, under `tests/Fixtures/process/` (`echo-args.php`,
`sleep.php`, `exit-code.php`, `stdout-stderr.php`, `huge-output.php`),
invoked via `PHP_BINARY`. This mirrors Phase 1's Discovery security
philosophy ("never execute anything from the analyzed project") extended
to this phase's new process-execution surface: nothing under
`tests/Fixtures/process/` originates from or resembles a target project,
so real process execution can be tested without ever running target code.

A dedicated static test
(`tests/Unit/Audit/Engine/NoShellExecutionTest.php`) scans every file
under `app/Audit/Engine/` **and** `app/Audit/Analyzers/` (comments
stripped) for `shell_exec`/`exec`/`system`/`passthru`/`proc_open`/`popen`
and fails if any appear directly in LaraDogs' own code — Symfony's own
internal use of `proc_open` inside its vendored `Process` class is
outside this scan and is the accepted, documented exception (see ADR-0011).
A separate test (`DependencyDirectionTest.php`) statically proves
`app/Audit/Engine/` never references `App\Audit\Findings` or
`App\Audit\Analyzers`, protecting the dependency direction this whole
design depends on.
