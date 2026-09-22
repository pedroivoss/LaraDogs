<?php

use App\Audit\Engine\Process\ProcessCommand;
use App\Audit\Engine\Process\ProcessResult;
use App\Audit\Engine\Process\ProcessRunner;
use App\Audit\Engine\Process\SymfonyProcessRunner;
use App\Audit\Source\Git\GitRepositoryInspector;
use App\Audit\Source\Git\GitRepositoryState;
use Symfony\Component\Process\Process;
use Tests\Support\Git\GitFixture;
use Tests\Support\Process\RecordingProcessRunner;

/*
 * Real `git`, real (hostile) repositories, controlled marker files. Each
 * hostile repo is first shown to be genuinely dangerous with a plain,
 * un-hardened `git status` (the CONTROL), then inspected through
 * GitRepositoryInspector — which must never run any of it.
 */

beforeEach(function () {
    if (! GitFixture::available()) {
        $this->markTestSkipped('The git binary is not installed.');
    }

    $this->marker = sys_get_temp_dir().'/laradogs-marker-'.bin2hex(random_bytes(6));
    $this->touchCommand = 'touch '.$this->marker;
    $this->envBackup = [];
});

afterEach(function () {
    GitFixture::cleanupAll();
    @unlink($this->marker);

    foreach ($this->envBackup as $key => $value) {
        $value === false ? putenv($key) : putenv("{$key}={$value}");
    }
});

function secInspector(?ProcessRunner $runner = null): GitRepositoryInspector
{
    return new GitRepositoryInspector(
        runner: $runner ?? new SymfonyProcessRunner(65_536),
        home: sys_get_temp_dir().'/laradogs-no-home-'.getmypid(),
    );
}

/** Makes `a.txt` "stat-dirty" so Git must re-hash it (running any clean filter). */
function secTouchTracked(GitFixture $repo, string $file = 'README.md'): void
{
    touch($repo->path.'/'.$file, time() + 30);
}

/** A plain, un-hardened `git status` — used only as the positive CONTROL. */
function secPlainStatus(GitFixture $repo): void
{
    $process = new Process(['git', 'status', '--porcelain'], $repo->path, [
        'GIT_CONFIG_GLOBAL' => '/dev/null',
        'GIT_CONFIG_NOSYSTEM' => '1',
    ]);
    $process->run();
}

function secSetEnv(object $test, string $key, string $value): void
{
    $test->envBackup[$key] = getenv($key);
    putenv("{$key}={$value}");
}

// ---------------- hostile repository configuration ----------------

it('never runs a clean filter, even though a plain git status would', function () {
    $repo = GitFixture::repository();
    $repo->write('.gitattributes', "*.md filter=evil\n");
    $repo->commitAll('attributes');
    $repo->config('filter.evil.clean', "sh -c '{$this->touchCommand}; cat'");
    secTouchTracked($repo);

    secPlainStatus($repo);
    expect(file_exists($this->marker))->toBeTrue('CONTROL: the fixture must be genuinely dangerous');
    unlink($this->marker);
    secTouchTracked($repo);

    $snapshot = secInspector()->inspect($repo->path);

    expect($snapshot->state)->toBe(GitRepositoryState::Repository)
        ->and(file_exists($this->marker))->toBeFalse();
});

it('never runs a long-running process filter', function () {
    $repo = GitFixture::repository();
    $repo->write('.gitattributes', "*.md filter=evil\n");
    $repo->commitAll('attributes');
    $repo->config('filter.evil.process', "sh -c '{$this->touchCommand}; cat >/dev/null'");
    $repo->config('filter.evil.required', 'true');
    secTouchTracked($repo);

    $snapshot = secInspector()->inspect($repo->path);

    expect($snapshot->state)->toBe(GitRepositoryState::Repository)
        ->and(file_exists($this->marker))->toBeFalse();
});

it('never runs a core.fsmonitor command', function () {
    $repo = GitFixture::repository();
    $repo->config('core.fsmonitor', "sh -c '{$this->touchCommand}; echo'");

    secPlainStatus($repo);
    expect(file_exists($this->marker))->toBeTrue('CONTROL: the fixture must be genuinely dangerous');
    unlink($this->marker);

    secInspector()->inspect($repo->path);

    expect(file_exists($this->marker))->toBeFalse();
});

it('does not let a malicious git alias alter the commands', function () {
    $repo = GitFixture::repository();

    foreach (['status', 'rev-parse', 'config', 'cat-file', 'log'] as $builtin) {
        $repo->config("alias.{$builtin}", "!{$this->touchCommand}");
    }

    $snapshot = secInspector()->inspect($repo->path);

    expect($snapshot->state)->toBe(GitRepositoryState::Repository)
        ->and($snapshot->commitSha)->toBe($repo->sha())
        ->and(file_exists($this->marker))->toBeFalse();
});

it('never invokes a credential helper', function () {
    $repo = GitFixture::repository();
    $repo->git('remote', 'add', 'origin', 'https://example.invalid/org/repo.git');
    $repo->config('credential.helper', "!{$this->touchCommand}");
    $repo->config('core.askPass', $this->touchCommand);
    $repo->config('core.sshCommand', $this->touchCommand);

    secInspector()->inspect($repo->path);

    expect(file_exists($this->marker))->toBeFalse();
});

it('never invokes an external diff or textconv', function () {
    $repo = GitFixture::repository();
    $repo->write('.gitattributes', "*.md diff=evil\n");
    $repo->commitAll('attributes');
    $repo->config('diff.external', $this->touchCommand);
    $repo->config('diff.evil.textconv', $this->touchCommand);
    $repo->write('README.md', "changed\n");

    secInspector()->inspect($repo->path);

    expect(file_exists($this->marker))->toBeFalse();
});

it('does not block on a hostile pager', function () {
    $repo = GitFixture::repository();
    $repo->config('core.pager', 'sleep 60');
    $repo->config('pager.status', 'sleep 60');
    $repo->config('pager.log', 'sleep 60');

    $started = hrtime(true);
    $snapshot = secInspector()->inspect($repo->path);

    expect($snapshot->state)->toBe(GitRepositoryState::Repository)
        ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(8.0);
});

it('does not execute repository hooks', function () {
    $repo = GitFixture::repository();
    $hooks = $repo->path.'/.git/hooks';

    foreach (['pre-commit', 'post-checkout', 'post-index-change', 'reference-transaction', 'fsmonitor-watchman', 'post-merge'] as $hook) {
        file_put_contents("{$hooks}/{$hook}", "#!/bin/sh\n{$this->touchCommand}\n");
        chmod("{$hooks}/{$hook}", 0o755);
    }

    $repo->config('core.fsmonitor', '.git/hooks/fsmonitor-watchman');
    $repo->write('README.md', "dirty\n");

    secInspector()->inspect($repo->path);

    expect(file_exists($this->marker))->toBeFalse();
});

it('does not honor a hostile core.hooksPath', function () {
    $repo = GitFixture::repository();
    $evil = GitFixture::directory();
    file_put_contents($evil->path.'/fsmonitor-watchman', "#!/bin/sh\n{$this->touchCommand}\n");
    chmod($evil->path.'/fsmonitor-watchman', 0o755);
    $repo->config('core.hooksPath', $evil->path);
    $repo->config('core.fsmonitor', $evil->path.'/fsmonitor-watchman');

    secInspector()->inspect($repo->path);

    expect(file_exists($this->marker))->toBeFalse();
});

it('neutralizes filters smuggled in through an include', function () {
    $repo = GitFixture::repository();
    $repo->write('.gitattributes', "*.md filter=evil\n");
    $repo->commitAll('attributes');

    $included = $repo->path.'/.git/evil.inc';
    file_put_contents($included, "[filter \"evil\"]\n\tclean = sh -c '{$this->touchCommand}; cat'\n");
    $repo->config('include.path', $included);
    secTouchTracked($repo);

    secInspector()->inspect($repo->path);

    expect(file_exists($this->marker))->toBeFalse();
});

it('refuses a repository configuration that redirects the work tree', function () {
    $repo = GitFixture::repository();
    $elsewhere = GitFixture::directory();
    $repo->config('core.worktree', $elsewhere->path);

    $snapshot = secInspector()->inspect($repo->path);

    expect($snapshot->state)->toBe(GitRepositoryState::Unavailable)
        ->and($snapshot->unavailableReason)->toBe('unsafe_config');
});

// ---------------- inherited environment & host config ----------------

it('does not inherit hostile variables from the parent environment', function () {
    $repo = GitFixture::repository();

    secSetEnv($this, 'GIT_ASKPASS', $this->touchCommand);
    secSetEnv($this, 'SSH_ASKPASS', $this->touchCommand);
    secSetEnv($this, 'GIT_EXTERNAL_DIFF', $this->touchCommand);
    secSetEnv($this, 'GIT_PAGER', 'sleep 60');
    secSetEnv($this, 'PAGER', 'sleep 60');
    secSetEnv($this, 'GIT_SSH_COMMAND', $this->touchCommand);
    // A bogus GIT_DIR would break discovery if it were inherited.
    secSetEnv($this, 'GIT_DIR', '/nonexistent/.git');
    secSetEnv($this, 'GIT_WORK_TREE', '/nonexistent');

    $snapshot = secInspector()->inspect($repo->path);

    expect($snapshot->state)->toBe(GitRepositoryState::Repository)
        ->and($snapshot->commitSha)->toBe($repo->sha())
        ->and(file_exists($this->marker))->toBeFalse();
});

it('neutralizes global and system git config and the HOME credential store', function () {
    $repo = GitFixture::repository();
    $repo->write('.gitattributes', "*.md filter=evil\n");
    $repo->commitAll('attributes');
    secTouchTracked($repo);

    $fakeHome = GitFixture::directory();
    file_put_contents($fakeHome->path.'/.gitconfig', "[filter \"evil\"]\n\tclean = sh -c '{$this->touchCommand}; cat'\n[core]\n\tfsmonitor = {$this->touchCommand}\n[credential]\n\thelper = !{$this->touchCommand}\n");
    file_put_contents($fakeHome->path.'/.git-credentials', "https://user:secret@example.com\n");

    secSetEnv($this, 'HOME', $fakeHome->path);
    secSetEnv($this, 'XDG_CONFIG_HOME', $fakeHome->path);
    secSetEnv($this, 'GIT_CONFIG_GLOBAL', $fakeHome->path.'/.gitconfig');
    secSetEnv($this, 'GIT_CONFIG_SYSTEM', $fakeHome->path.'/.gitconfig');

    $snapshot = secInspector()->inspect($repo->path);

    expect($snapshot->state)->toBe(GitRepositoryState::Repository)
        ->and(file_exists($this->marker))->toBeFalse();
});

it('never writes to the repository or any git config (read-only inspection)', function () {
    $repo = GitFixture::repository();
    $repo->write('README.md', "dirty\n");
    $before = [
        'index' => hash_file('sha256', $repo->path.'/.git/index'),
        'config' => hash_file('sha256', $repo->path.'/.git/config'),
        'index_mtime' => filemtime($repo->path.'/.git/index'),
    ];

    secInspector()->inspect($repo->path);

    expect(hash_file('sha256', $repo->path.'/.git/index'))->toBe($before['index'])
        ->and(hash_file('sha256', $repo->path.'/.git/config'))->toBe($before['config'])
        ->and(file_exists($repo->path.'/.git/index.lock'))->toBeFalse()
        ->and(filemtime($repo->path.'/.git/index'))->toBe($before['index_mtime']);
});

it('works on a read-only mounted repository', function () {
    $repo = GitFixture::repository();
    $repo->write('README.md', "dirty\n");
    (new Process(['chmod', '-R', 'a-w', $repo->path]))->run();

    $snapshot = secInspector()->inspect($repo->path);

    expect($snapshot->state)->toBe(GitRepositoryState::Repository)
        ->and($snapshot->dirty)->toBeTrue()
        ->and($snapshot->commitSha)->toBe($repo->sha());
});

// ---------------- command surface ----------------

it('runs only read-only local git subcommands, argv-only, with a fixed environment', function () {
    $repo = GitFixture::repository();
    $repo->git('remote', 'add', 'origin', 'https://user:pw@127.0.0.1:1/org/repo.git');
    $recorder = new RecordingProcessRunner(new SymfonyProcessRunner(65_536));

    secSetEnv($this, 'GITHUB_TOKEN', 'ghp_should_not_leak');
    secSetEnv($this, 'DB_PASSWORD', 'should_not_leak');

    secInspector($recorder)->inspect($repo->path);

    expect($recorder->subcommands())->toBe(['rev-parse', 'config', 'status', 'cat-file']);

    foreach ($recorder->commands as $command) {
        expect($command->argv[0])->toBe('git')
            ->and($command->argv)->not->toContain('sh')
            ->and($command->argv)->not->toContain('-c=sh')
            ->and($command->argv)->not->toContain('fetch')
            ->and($command->argv)->not->toContain('pull')
            ->and($command->argv)->not->toContain('push')
            ->and($command->argv)->not->toContain('ls-remote')
            ->and($command->argv)->not->toContain('clone')
            ->and($command->argv)->not->toContain('checkout')
            ->and($command->argv)->not->toContain('safe.directory=*')
            ->and($command->argv)->toContain('safe.directory='.$repo->path)
            ->and($command->argv)->toContain('--no-optional-locks')
            ->and($command->argv)->toContain('--no-pager')
            ->and(array_keys($command->environment))->not->toContain('GITHUB_TOKEN')
            ->and(array_keys($command->environment))->not->toContain('DB_PASSWORD')
            ->and(array_keys($command->environment))->not->toContain('GIT_ASKPASS')
            ->and(array_keys($command->environment))->not->toContain('SSH_ASKPASS')
            ->and($command->environment['GIT_TERMINAL_PROMPT'])->toBe('0')
            ->and($command->environment['GIT_CONFIG_GLOBAL'])->toBe('/dev/null')
            ->and($command->environment['GIT_CONFIG_NOSYSTEM'])->toBe('1')
            ->and($command->environment['GIT_OPTIONAL_LOCKS'])->toBe('0')
            ->and($command->environment['GIT_CEILING_DIRECTORIES'])->toBe(dirname($repo->path))
            ->and($command->environment['HOME'])->toStartWith(sys_get_temp_dir().'/laradogs-no-home')
            ->and($command->workingDirectory)->toBe($repo->path);
    }
});

it('gives every command a small dedicated timeout, never an analyzer-sized one', function () {
    $repo = GitFixture::repository();
    $recorder = new RecordingProcessRunner(new SymfonyProcessRunner(65_536));

    secInspector($recorder)->inspect($repo->path);

    foreach ($recorder->commands as $command) {
        expect($command->timeoutSeconds)->toBeLessThanOrEqual(10)->toBeGreaterThanOrEqual(1);
    }
});

it('reports a timeout as unavailable instead of guessing', function () {
    $repo = GitFixture::repository();
    $stuck = new class implements ProcessRunner
    {
        public function run(ProcessCommand $command): ProcessResult
        {
            return new ProcessResult(null, '', '', true, false, 10_000);
        }
    };

    $snapshot = secInspector($stuck)->inspect($repo->path);

    expect($snapshot->state)->toBe(GitRepositoryState::Unavailable)
        ->and($snapshot->unavailableReason)->toBe('timeout');
});

it('cannot be talked into a shell: the target path stays one argv/cwd element', function () {
    $dir = GitFixture::directory();
    $evil = $dir->path.'/a; touch pwned #$(touch pwned2)';
    mkdir($evil);
    (new Process(['git', 'init', '-q', '-b', 'main'], $evil, ['GIT_CONFIG_GLOBAL' => '/dev/null']))->run();

    $snapshot = secInspector()->inspect($evil);

    expect($snapshot->state)->toBe(GitRepositoryState::Repository)
        ->and(file_exists($evil.'/pwned'))->toBeFalse()
        ->and(file_exists($evil.'/pwned2'))->toBeFalse()
        ->and(file_exists($dir->path.'/pwned'))->toBeFalse()
        ->and(file_exists(getcwd().'/pwned'))->toBeFalse();
});

it('contains no shell or process-execution calls in the Git source layer', function () {
    $root = dirname(__DIR__, 4).'/app/Audit/Source';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    $offenders = [];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $code = php_strip_whitespace($file->getPathname());

        foreach (['shell_exec(', 'exec(', 'system(', 'passthru(', 'proc_open(', 'popen(', 'Process::fromShellCommandline', '`', "'sh'", "'-c', 'sh", 'bash'] as $needle) {
            if (str_contains($code, $needle)) {
                $offenders[] = $file->getPathname()." contains {$needle}";
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('never issues network-capable git subcommands anywhere in the Source layer', function () {
    $root = dirname(__DIR__, 4).'/app/Audit/Source';
    $code = '';

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') {
            $code .= php_strip_whitespace($file->getPathname());
        }
    }

    foreach (['fetch', 'pull', 'push', 'ls-remote', 'clone', 'submodule', 'lfs', 'remote update'] as $word) {
        expect($code)->not->toContain("'{$word}'");
    }
});
