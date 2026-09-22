<?php

use App\Audit\Engine\Process\SymfonyProcessRunner;
use App\Audit\Source\Git\GitRepositoryInspector;
use App\Audit\Source\Git\GitRepositoryState;
use Symfony\Component\Process\Process;
use Tests\Support\Git\GitFixture;
use Tests\Support\Process\RecordingProcessRunner;

/*
 * Config trust boundary (Phase 9.1). Verified against real Git: a plain
 * `git config --list` FOLLOWS include.path / includeIf.*.path — absolute,
 * `../`, `~/`, symlinked — reading files OUTSIDE the repository, and such
 * included config can execute commands. LaraDogs therefore reads the
 * repository config with --no-includes and refuses ANY include declaration
 * (`unsafe_config`). Every case first proves the hostile config really
 * executes with a plain git command (the CONTROL), then that the inspector
 * refuses it and nothing runs.
 */

beforeEach(function () {
    if (! GitFixture::available()) {
        $this->markTestSkipped('The git binary is not installed.');
    }

    $this->marker = sys_get_temp_dir().'/laradogs-incmarker-'.bin2hex(random_bytes(6));
    $this->touch = 'touch '.$this->marker;
});

afterEach(function () {
    GitFixture::cleanupAll();
    @unlink($this->marker);
});

function incInspector(?RecordingProcessRunner $recorder = null): GitRepositoryInspector
{
    return new GitRepositoryInspector($recorder ?? new SymfonyProcessRunner(65_536), home: sys_get_temp_dir().'/laradogs-no-home-'.getmypid());
}

/** Runs a plain, un-hardened git command (the CONTROL). */
function incPlain(GitFixture $repo, array $argv, array $env = [], ?string $stdin = null): void
{
    $process = new Process($argv, $repo->path, ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1', ...$env]);

    if ($stdin !== null) {
        $process->setInput($stdin);
    }

    $process->run();
}

/** An external config file whose contents execute a marker via fsmonitor, a clean filter and a credential helper. */
function incEvilFile(object $test, string $path): void
{
    file_put_contents($path, "[core]\n\tfsmonitor = {$test->touch}\n[filter \"evil\"]\n\tclean = sh -c '{$test->touch}; cat'\n[credential]\n\thelper = !{$test->touch}\n");
}

function incRepo(): GitFixture
{
    $repo = GitFixture::repository();
    $repo->write('.gitattributes', "*.md filter=evil\n");
    $repo->commitAll('attributes');

    return $repo;
}

/** Each include form: [description => fn(GitFixture $repo, string $evilFile, string $fakeHome): array{env: array}] */
dataset('include forms', [
    'plain absolute include' => [function (GitFixture $repo, string $evil): array {
        $repo->config('include.path', $evil);

        return [];
    }],
    'relative ../ include' => [function (GitFixture $repo, string $evil): array {
        $copy = dirname($repo->path).'/'.basename($evil);
        copy($evil, $copy);
        // Resolved relative to .git/, i.e. two levels up = the repository's parent directory.
        $repo->config('include.path', '../../'.basename($evil));

        return [];
    }],
    'symlink include escape' => [function (GitFixture $repo, string $evil): array {
        symlink($evil, $repo->path.'/.git/link.inc');
        $repo->config('include.path', 'link.inc');

        return [];
    }],
    'HOME-expanded include' => [function (GitFixture $repo, string $evil, string $home): array {
        copy($evil, $home.'/evil.inc');
        $repo->config('include.path', '~/evil.inc');

        return ['HOME' => $home];
    }],
    'includeIf onbranch' => [function (GitFixture $repo, string $evil): array {
        $repo->config('includeIf.onbranch:main.path', $evil);

        return [];
    }],
    'includeIf hasconfig' => [function (GitFixture $repo, string $evil): array {
        $repo->git('remote', 'add', 'origin', 'https://example.invalid/x.git');
        $repo->config('includeIf.hasconfig:remote.*.url:https://**.path', $evil);

        return [];
    }],
    'includeIf gitdir' => [function (GitFixture $repo, string $evil): array {
        $repo->config('includeIf.gitdir:'.$repo->path.'/.git.path', $evil);

        return [];
    }],
]);

it('refuses every include form and never runs the included config', function (Closure $setup) {
    $repo = incRepo();
    $outside = GitFixture::directory();
    $home = GitFixture::directory();
    $evil = $outside->path.'/evil.inc';
    incEvilFile($this, $evil);
    $env = $setup($repo, $evil, $home->path);

    // CONTROL: without the protection the included config really executes.
    incPlain($repo, ['git', 'status', '--porcelain'], $env);
    expect(file_exists($this->marker))->toBeTrue('CONTROL: the included config must be genuinely dangerous');
    unlink($this->marker);

    $recorder = new RecordingProcessRunner(new SymfonyProcessRunner(65_536));
    $snapshot = incInspector($recorder)->inspect($repo->path);

    expect($snapshot->state)->toBe(GitRepositoryState::Unavailable)
        ->and($snapshot->unavailableReason)->toBe('unsafe_config')
        ->and(file_exists($this->marker))->toBeFalse();

    // The config was read WITHOUT following includes, and nothing ran after the refusal.
    $configCall = collect($recorder->commands)->first(fn ($c) => in_array('config', $c->argv, true));
    expect($configCall->argv)->toContain('--no-includes')
        ->and($recorder->subcommands())->toBe(['rev-parse', 'config']);
})->with('include forms');

it('never lets an included filter, fsmonitor or credential helper execute', function (string $control) {
    $repo = incRepo();
    $outside = GitFixture::directory();
    $evil = $outside->path.'/evil.inc';
    incEvilFile($this, $evil);
    $repo->config('include.path', $evil);
    touch($repo->path.'/README.md', time() + 30);

    // CONTROL per execution path.
    match ($control) {
        'filter' => incPlain($repo, ['git', 'status', '--porcelain']),
        'fsmonitor' => incPlain($repo, ['git', 'status', '--porcelain']),
        'credential' => incPlain($repo, ['git', 'credential', 'fill'], stdin: "protocol=https\nhost=example.invalid\n\n"),
    };
    expect(file_exists($this->marker))->toBeTrue("CONTROL ({$control}) must be genuinely dangerous");
    unlink($this->marker);
    touch($repo->path.'/README.md', time() + 60);

    $snapshot = incInspector()->inspect($repo->path);

    expect($snapshot->unavailableReason)->toBe('unsafe_config')
        ->and(file_exists($this->marker))->toBeFalse();
})->with(['filter', 'fsmonitor', 'credential']);

it('does not read the included file at all (its contents cannot influence the snapshot)', function () {
    $repo = incRepo();
    $outside = GitFixture::directory();
    // Not even valid config: reading it as config would produce a Git error/leak.
    file_put_contents($outside->path.'/secret.inc', "TOP-SECRET-CONTENT-NOT-CONFIG\n");
    $repo->config('include.path', $outside->path.'/secret.inc');

    $snapshot = incInspector()->inspect($repo->path);

    expect($snapshot->unavailableReason)->toBe('unsafe_config')
        ->and(json_encode($snapshot))->not->toContain('TOP-SECRET');
});

it('still supports an ordinary linked worktree', function () {
    $repo = GitFixture::repository();
    $worktree = dirname($repo->path).'/'.basename($repo->path).'-wt';
    $repo->git('worktree', 'add', '-q', '-b', 'feature', $worktree);

    try {
        $snapshot = incInspector()->inspect($worktree);

        expect($snapshot->state)->toBe(GitRepositoryState::Repository)->and($snapshot->branch)->toBe('feature');
    } finally {
        $repo->git('worktree', 'remove', '--force', $worktree);
    }
});

it('does not let config.worktree route around the include policy', function () {
    $repo = incRepo();
    $outside = GitFixture::directory();
    $evil = $outside->path.'/evil.inc';
    incEvilFile($this, $evil);
    $worktree = dirname($repo->path).'/'.basename($repo->path).'-wt';
    $repo->git('worktree', 'add', '-q', '-b', 'feature', $worktree);
    $repo->git('config', 'extensions.worktreeConfig', 'true');

    try {
        (new Process(['git', 'config', '--worktree', 'include.path', $evil], $worktree, ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1']))->mustRun();
        expect(file_exists($worktree.'/../'.basename($repo->path).'/.git/worktrees'))->toBeTrue();

        // CONTROL: the linked worktree's own config file makes plain git execute it.
        (new Process(['git', 'status', '--porcelain'], $worktree, ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1']))->run();
        expect(file_exists($this->marker))->toBeTrue('CONTROL: config.worktree include must be genuinely dangerous');
        unlink($this->marker);

        $snapshot = incInspector()->inspect($worktree);

        expect($snapshot->unavailableReason)->toBe('unsafe_config')
            ->and(file_exists($this->marker))->toBeFalse();
    } finally {
        $repo->git('worktree', 'remove', '--force', $worktree);
    }
});

it('neutralizes execution config placed directly in config.worktree', function () {
    $repo = incRepo();
    $worktree = dirname($repo->path).'/'.basename($repo->path).'-wt';
    $repo->git('worktree', 'add', '-q', '-b', 'feature', $worktree);
    $repo->git('config', 'extensions.worktreeConfig', 'true');
    $env = ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1'];
    (new Process(['git', 'config', '--worktree', 'filter.evil.clean', "sh -c '{$this->touch}; cat'"], $worktree, $env))->mustRun();
    (new Process(['git', 'config', '--worktree', 'core.fsmonitor', $this->touch], $worktree, $env))->mustRun();
    touch($worktree.'/README.md', time() + 30);

    try {
        (new Process(['git', 'status', '--porcelain'], $worktree, $env))->run();
        expect(file_exists($this->marker))->toBeTrue('CONTROL: config.worktree must be genuinely dangerous');
        unlink($this->marker);
        touch($worktree.'/README.md', time() + 60);

        $snapshot = incInspector()->inspect($worktree);

        expect($snapshot->state)->toBe(GitRepositoryState::Repository)
            ->and(file_exists($this->marker))->toBeFalse();
    } finally {
        $repo->git('worktree', 'remove', '--force', $worktree);
    }
});

it('reconfirms the remaining execution-capable keys stay inert next to the include policy', function () {
    $repo = incRepo();
    foreach (['credential.helper' => "!{$this->touch}", 'core.sshCommand' => $this->touch, 'core.askPass' => $this->touch, 'diff.external' => $this->touch, 'core.pager' => $this->touch, 'core.editor' => $this->touch, 'core.hooksPath' => '/nonexistent'] as $key => $value) {
        $repo->config($key, $value);
    }
    $repo->config('alias.status', "!{$this->touch}");

    $snapshot = incInspector()->inspect($repo->path);

    expect($snapshot->state)->toBe(GitRepositoryState::Repository)->and(file_exists($this->marker))->toBeFalse();
});
