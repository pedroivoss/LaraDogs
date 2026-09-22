<?php

namespace App\Audit\Source\Git;

use App\Audit\Engine\Process\ProcessCommand;
use App\Audit\Engine\Process\ProcessResult;
use App\Audit\Engine\Process\ProcessRunner;
use DateTimeImmutable;
use Throwable;

/**
 * The ONE place LaraDogs runs Git. Observes the LOCAL state of a mounted
 * project directory — never fetches, pulls, pushes, checks out, or contacts
 * a remote — and never throws: any problem becomes a
 * {@see GitRepositoryState::Unavailable} snapshot.
 *
 * The target repository is UNTRUSTED. Verified empirically against a real
 * Git binary (see tests/Feature/Audit/Source/GitInspectorSecurityTest.php):
 * a repository's own `.git/config` can make a plain `git status` execute
 * arbitrary commands (`core.fsmonitor`, `filter.<name>.clean|process`).
 * Hence:
 *
 * - argv only, never a shell; every command and flag is chosen here;
 * - a fully explicit environment (the runner suppresses everything not
 *   listed): no inherited HOME/credentials/askpass/pager/external diff, no
 *   system or global config, no terminal prompts, no optional index lock
 *   (the mount may be read-only), `GIT_CEILING_DIRECTORIES` so discovery
 *   can never ascend out of the project directory;
 * - the repository's config is READ first WITHOUT following includes
 *   (`git config --no-includes --list`, which executes nothing — verified: a
 *   plain `--list` follows `include.path`/`includeIf.*.path` to files
 *   OUTSIDE the repository via absolute paths, `../`, `~/` and symlinks, and
 *   such a config can execute commands). ANY include declaration, or a
 *   config that redirects the work tree (`core.worktree`), is refused as
 *   `unsafe_config` (fail closed — no narrow "safe" subset is supported).
 *   Every `filter.*.clean|smudge|process` the config defines is overridden
 *   on the command line, and `core.fsmonitor`/`core.hooksPath` are always
 *   forced off. Linked-worktree config (`config.worktree`) is part of the
 *   same listing, so it cannot route around any of this;
 * - `safe.directory` is scoped, per command, to this one validated path —
 *   never `*`, never written to any config file;
 * - only these read-only commands run: `rev-parse`, `config --list`,
 *   `status --porcelain=v2`, `cat-file commit`. No `fetch`/`pull`/`ls-remote`;
 * - stdout/stderr are size-capped and the whole inspection has one small
 *   wall-clock budget.
 *
 * Aliases cannot alter these commands (Git never lets an alias shadow a
 * built-in), and hooks are not run by any of them.
 */
final class GitRepositoryInspector
{
    private const int MAX_NEUTRALIZED_KEYS = 32;

    private const int MAX_BRANCH_LENGTH = 255;

    private const int MAX_SUBJECT_LENGTH = 200;

    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly string $binary = 'git',
        private readonly int $budgetSeconds = 10,
        private readonly string $home = '/nonexistent',
    ) {}

    public function inspect(string $path): GitSnapshot
    {
        $real = realpath($path);

        if ($real === false || ! is_dir($real)) {
            return GitSnapshot::unavailable('path_unavailable');
        }

        try {
            return $this->inspectDirectory($real);
        } catch (Throwable) {
            return GitSnapshot::unavailable('inspection_error');
        }
    }

    private function inspectDirectory(string $dir): GitSnapshot
    {
        $deadline = hrtime(true) + $this->budgetSeconds * 1_000_000_000;

        // 1. Is this directory itself a Git repository (and a bare one)?
        $probe = $this->git($dir, $deadline, [], ['rev-parse', '--is-bare-repository']);

        if ($probe instanceof GitSnapshot) {
            return $probe;
        }

        if (! $probe->successful()) {
            return $this->isNotRepository($probe)
                ? GitSnapshot::notRepository()
                : GitSnapshot::unavailable($this->failureReason($probe));
        }

        $bare = trim($probe->stdout);

        if ($bare === 'true') {
            return GitSnapshot::bare();
        }

        if ($bare !== 'false') {
            return GitSnapshot::unavailable('git_error');
        }

        // 2. Read the effective config (executes nothing) to find what must be neutralized.
        $config = $this->git($dir, $deadline, [], ['config', '--no-includes', '--list', '-z']);

        if ($config instanceof GitSnapshot) {
            return $config;
        }

        if (! $config->successful() || $config->outputTruncated) {
            return GitSnapshot::unavailable($config->outputTruncated ? 'output_too_large' : $this->failureReason($config));
        }

        $parsed = $this->parseConfig($config->stdout);

        if ($parsed === null) {
            return GitSnapshot::unavailable('unsafe_config');
        }

        [$neutralize, $remote] = $parsed;

        // 3. Branch, HEAD and dirty state in one command.
        $status = $this->git($dir, $deadline, $neutralize, [
            'status', '--porcelain=v2', '--branch', '-z', '--untracked-files=normal', '--ignore-submodules=all',
        ]);

        if ($status instanceof GitSnapshot) {
            return $status;
        }

        if (! $status->successful()) {
            return GitSnapshot::unavailable($this->failureReason($status));
        }

        $head = $this->parseStatus($status->stdout);

        if ($head === null) {
            return GitSnapshot::unavailable($status->outputTruncated ? 'output_too_large' : 'git_error');
        }

        [$sha, $branch, $detached, $dirty] = $head;

        $timestamp = null;
        $subject = null;

        if ($sha !== null) {
            $commit = $this->git($dir, $deadline, $neutralize, ['cat-file', 'commit', $sha]);

            if ($commit instanceof ProcessResult && $commit->successful()) {
                [$timestamp, $subject] = $this->parseCommit($commit->stdout);
            }
        }

        return new GitSnapshot(
            state: GitRepositoryState::Repository,
            commitSha: $sha,
            branch: $branch,
            detached: $detached,
            dirty: $dirty,
            commitTimestamp: $timestamp,
            commitSubject: $subject,
            remoteOrigin: RemoteUrlSanitizer::sanitize($remote),
        );
    }

    /**
     * @param  list<string>  $neutralize  extra `-c key=value` overrides
     * @param  list<string>  $args
     * @return ProcessResult|GitSnapshot a snapshot only when the budget ran out
     */
    private function git(string $dir, int $deadline, array $neutralize, array $args): ProcessResult|GitSnapshot
    {
        $remainingNs = $deadline - hrtime(true);

        if ($remainingNs <= 0) {
            return GitSnapshot::unavailable('timeout');
        }

        $argv = [
            $this->binary,
            '--no-pager',
            '--no-optional-locks',
            '-c', 'core.fsmonitor=false',
            '-c', 'core.hooksPath=/dev/null',
            '-c', 'core.pager=cat',
            '-c', 'safe.directory='.$dir,
        ];

        foreach ($neutralize as $override) {
            $argv[] = '-c';
            $argv[] = $override;
        }

        foreach ($args as $arg) {
            $argv[] = $arg;
        }

        $result = $this->runner->run(new ProcessCommand(
            argv: $argv,
            workingDirectory: $dir,
            environment: $this->environment($dir),
            timeoutSeconds: max(1, (int) ceil($remainingNs / 1_000_000_000)),
        ));

        return $result;
    }

    /**
     * The COMPLETE environment Git runs with — the process runner removes
     * everything else, so nothing from the parent (credentials, askpass,
     * pager, external diff, GIT_DIR, ...) can reach it.
     *
     * @return array<string,string>
     */
    private function environment(string $dir): array
    {
        $env = [
            'PATH' => (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
            'HOME' => $this->home,
            'LC_ALL' => 'C',
            'LANG' => 'C',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_OPTIONAL_LOCKS' => '0',
            'GIT_NO_REPLACE_OBJECTS' => '1',
            'GIT_ATTR_NOSYSTEM' => '1',
            'GIT_PAGER' => 'cat',
            'PAGER' => 'cat',
        ];

        $parent = dirname($dir);

        if ($parent !== $dir) {
            // Discovery may only consider the project directory itself.
            $env['GIT_CEILING_DIRECTORIES'] = $parent;
        }

        return $env;
    }

    /**
     * @return array{0: list<string>, 1: string|null}|null overrides + raw origin URL, or null when the config is unsafe
     */
    private function parseConfig(string $raw): ?array
    {
        $neutralize = [];
        $remote = null;

        foreach (explode("\0", $raw) as $entry) {
            if ($entry === '') {
                continue;
            }

            $newline = strpos($entry, "\n");
            $key = $newline === false ? $entry : substr($entry, 0, $newline);
            $value = $newline === false ? '' : substr($entry, $newline + 1);
            $lower = strtolower($key);

            // Repository-controlled includes would import configuration
            // from OUTSIDE the validated repository (and could re-enable
            // filters/fsmonitor/credential helpers): refuse them all.
            if ($lower === 'core.worktree' || str_starts_with($lower, 'include.') || str_starts_with($lower, 'includeif.')) {
                return null;
            }

            if ($lower === 'remote.origin.url') {
                $remote = $value;

                continue;
            }

            if (preg_match('/^filter\..+\.(clean|smudge|process|required)$/i', $key, $m) === 1) {
                if (str_contains($key, '=') || preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
                    return null;
                }

                $neutralize[$key] = $key.'='.(strtolower($m[1]) === 'required' ? 'false' : '');

                if (count($neutralize) > self::MAX_NEUTRALIZED_KEYS) {
                    return null;
                }
            }
        }

        return [array_values($neutralize), $remote];
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: bool, 3: bool}|null sha (null = no commits), branch, detached, dirty
     */
    private function parseStatus(string $stdout): ?array
    {
        $oid = null;
        $head = null;
        $dirty = false;

        foreach (explode("\0", $stdout) as $record) {
            if ($record === '') {
                continue;
            }

            if (str_starts_with($record, '# branch.oid ')) {
                $oid = substr($record, strlen('# branch.oid '));
            } elseif (str_starts_with($record, '# branch.head ')) {
                $head = substr($record, strlen('# branch.head '));
            } elseif (! str_starts_with($record, '# ')) {
                $dirty = true;
            }
        }

        if ($oid === null || $head === null) {
            return null;
        }

        if ($oid === '(initial)') {
            $sha = null;
        } elseif (preg_match('/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/', $oid) === 1) {
            $sha = $oid;
        } else {
            return null;
        }

        $detached = $head === '(detached)';
        $branch = $detached ? null : $this->cleanText($head, self::MAX_BRANCH_LENGTH);

        return [$sha, $branch, $detached, $dirty];
    }

    /**
     * Reads ONLY the committer timestamp and the first message line of the
     * raw commit object — never a name or an e-mail address.
     *
     * @return array{0: DateTimeImmutable|null, 1: string|null}
     */
    private function parseCommit(string $raw): array
    {
        $split = strpos($raw, "\n\n");
        $headers = $split === false ? $raw : substr($raw, 0, $split);
        $message = $split === false ? '' : substr($raw, $split + 2);

        $timestamp = null;

        if (preg_match('/^committer .* (\d{1,12}) [+-]\d{4}$/m', $headers, $m) === 1) {
            $timestamp = (new DateTimeImmutable)->setTimestamp((int) $m[1]);
        }

        $subject = null;

        foreach (explode("\n", $message) as $line) {
            if (trim($line) !== '') {
                $subject = $this->cleanText($line, self::MAX_SUBJECT_LENGTH);

                break;
            }
        }

        return [$timestamp, $subject];
    }

    private function cleanText(string $text, int $max): ?string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $text);
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }

    private function isNotRepository(ProcessResult $result): bool
    {
        return ! $result->timedOut
            && $result->exitCode === 128
            && str_contains($result->stderr, 'not a git repository');
    }

    private function failureReason(ProcessResult $result): string
    {
        return match (true) {
            $result->timedOut => 'timeout',
            $result->processStartFailed(), in_array($result->exitCode, [126, 127], true) => 'git_unavailable',
            str_contains($result->stderr, 'dubious ownership') => 'unsafe_ownership',
            default => 'git_error',
        };
    }
}
