<?php

use App\Audit\Engine\Process\SymfonyProcessRunner;
use App\Audit\Source\Git\GitRepositoryInspector;
use App\Audit\Source\Git\GitRepositoryState;
use Tests\Support\Git\GitFixture;

beforeEach(function () {
    if (! GitFixture::available()) {
        $this->markTestSkipped('The git binary is not installed.');
    }
});

afterEach(fn () => GitFixture::cleanupAll());

function gitInspector(int $maxOutput = 65_536): GitRepositoryInspector
{
    return new GitRepositoryInspector(
        runner: new SymfonyProcessRunner($maxOutput),
        home: sys_get_temp_dir().'/laradogs-no-home-'.getmypid(),
    );
}

// ---------------- detection ----------------

it('reports a non-Git directory as not a repository', function () {
    $dir = GitFixture::directory();

    expect(gitInspector()->inspect($dir->path)->state)->toBe(GitRepositoryState::NotRepository);
});

it('does not treat a directory inside another repository as a repository', function () {
    $repo = GitFixture::repository();
    $repo->write('packages/app/composer.json', '{}');

    // The ceiling keeps discovery from climbing out of the project directory.
    expect(gitInspector()->inspect($repo->path.'/packages/app')->state)->toBe(GitRepositoryState::NotRepository);
});

it('detects a normal repository', function () {
    $repo = GitFixture::repository();
    $snapshot = gitInspector()->inspect($repo->path);

    expect($snapshot->state)->toBe(GitRepositoryState::Repository)
        ->and($snapshot->isRepository())->toBeTrue()
        ->and($snapshot->hasCommit())->toBeTrue();
});

it('handles a repository without commits gracefully', function () {
    $repo = GitFixture::unborn();
    $snapshot = gitInspector()->inspect($repo->path);

    expect($snapshot->state)->toBe(GitRepositoryState::Repository)
        ->and($snapshot->commitSha)->toBeNull()
        ->and($snapshot->hasCommit())->toBeFalse()
        ->and($snapshot->branch)->toBe('main')
        ->and($snapshot->dirty)->toBeFalse()
        ->and($snapshot->isReproducible())->toBeFalse();
});

it('detects a detached HEAD without fabricating a branch', function () {
    $repo = GitFixture::repository();
    $sha = $repo->sha();
    $repo->detach();

    $snapshot = gitInspector()->inspect($repo->path);

    expect($snapshot->detached)->toBeTrue()
        ->and($snapshot->branch)->toBeNull()
        ->and($snapshot->commitSha)->toBe($sha);
});

it('detects a bare repository and does not treat it as a working tree', function () {
    $bare = GitFixture::bare();
    $snapshot = gitInspector()->inspect($bare->path);

    expect($snapshot->state)->toBe(GitRepositoryState::Bare)
        ->and($snapshot->commitSha)->toBeNull()
        ->and($snapshot->dirty)->toBeNull();
});

it('supports a linked worktree whose .git is a file', function () {
    $repo = GitFixture::repository();
    $worktree = dirname($repo->path).'/'.basename($repo->path).'-wt';
    $repo->git('worktree', 'add', '-q', '-b', 'feature', $worktree);

    try {
        expect(is_file($worktree.'/.git'))->toBeTrue();

        $snapshot = gitInspector()->inspect($worktree);

        expect($snapshot->state)->toBe(GitRepositoryState::Repository)
            ->and($snapshot->branch)->toBe('feature')
            ->and($snapshot->commitSha)->toBe($repo->sha())
            ->and($snapshot->dirty)->toBeFalse();
    } finally {
        $repo->git('worktree', 'remove', '--force', $worktree);
    }
});

// ---------------- dirty semantics ----------------

it('is clean for an untouched checkout', function () {
    $repo = GitFixture::repository();

    expect(gitInspector()->inspect($repo->path)->dirty)->toBeFalse();
});

it('is dirty for a modified tracked file', function () {
    $repo = GitFixture::repository();
    $repo->write('README.md', "changed\n");

    expect(gitInspector()->inspect($repo->path)->dirty)->toBeTrue();
});

it('is dirty for a staged change', function () {
    $repo = GitFixture::repository();
    $repo->write('README.md', "staged\n");
    $repo->git('add', 'README.md');

    expect(gitInspector()->inspect($repo->path)->dirty)->toBeTrue();
});

it('is dirty for an untracked file', function () {
    $repo = GitFixture::repository();
    $repo->write('new-file.php', '<?php');

    expect(gitInspector()->inspect($repo->path)->dirty)->toBeTrue();
});

it('ignores gitignored files', function () {
    $repo = GitFixture::repository();
    $repo->write('.gitignore', "vendor/\n");
    $repo->commitAll('ignore vendor');
    $repo->write('vendor/lib.php', '<?php');

    expect(gitInspector()->inspect($repo->path)->dirty)->toBeFalse();
});

// ---------------- metadata ----------------

it('captures the full SHA, branch, commit timestamp and subject', function () {
    $repo = GitFixture::repository("Ship the thing\n\nA longer body that must not be captured.");
    $snapshot = gitInspector()->inspect($repo->path);
    $expectedTimestamp = (int) trim($repo->git('log', '-1', '--format=%ct'));

    expect($snapshot->commitSha)->toMatch('/^[0-9a-f]{40}$/')
        ->and($snapshot->commitSha)->toBe($repo->sha())
        ->and($snapshot->shortSha())->toBe(substr($repo->sha(), 0, 7))
        ->and($snapshot->branch)->toBe('main')
        ->and($snapshot->detached)->toBeFalse()
        ->and($snapshot->commitTimestamp?->getTimestamp())->toBe($expectedTimestamp)
        ->and($snapshot->commitSubject)->toBe('Ship the thing');
});

it('never captures author or committer identity', function () {
    $repo = GitFixture::repository();
    $snapshot = gitInspector()->inspect($repo->path);
    $serialized = json_encode($snapshot->toScanAttributes(), JSON_THROW_ON_ERROR).json_encode($snapshot, JSON_PARTIAL_OUTPUT_ON_ERROR);

    expect($serialized)->not->toContain('author-secret')
        ->not->toContain('committer-secret')
        ->not->toContain('Fixture Author')
        ->not->toContain('Fixture Committer')
        ->not->toContain('@example.invalid');
});

it('bounds and sanitizes a hostile commit subject', function () {
    $repo = GitFixture::repository();
    $repo->write('a.txt', 'a');
    $repo->commitAll("\e[31mred\e[0m ".str_repeat('x', 500));

    $subject = gitInspector()->inspect($repo->path)->commitSubject;

    expect($subject)->not->toContain("\e")->and(mb_strlen((string) $subject))->toBeLessThanOrEqual(200);
});

it('sanitizes the origin remote read from the real config', function () {
    $repo = GitFixture::repository();
    $repo->git('remote', 'add', 'origin', 'https://build-user:ghp_SECRETTOKEN@example.com/org/repo.git');

    $snapshot = gitInspector()->inspect($repo->path);

    expect($snapshot->remoteOrigin)->toBe('https://example.com/org/repo.git')
        ->and(json_encode($snapshot->toScanAttributes()))->not->toContain('ghp_SECRETTOKEN')->not->toContain('build-user');
});

it('hides a local-path origin', function () {
    $repo = GitFixture::repository();
    $repo->git('remote', 'add', 'origin', '/home/someone/private/place.git');

    expect(gitInspector()->inspect($repo->path)->remoteOrigin)->toBe('Local remote');
});

it('has no remote when none is configured', function () {
    expect(gitInspector()->inspect(GitFixture::repository()->path)->remoteOrigin)->toBeNull();
});

it('reports an unavailable path without throwing', function () {
    $snapshot = gitInspector()->inspect('/definitely/not/a/real/path');

    expect($snapshot->state)->toBe(GitRepositoryState::Unavailable)
        ->and($snapshot->unavailableReason)->toBe('path_unavailable');
});

it('reports a missing git binary as unavailable rather than a repository state', function () {
    $repo = GitFixture::repository();
    $inspector = new GitRepositoryInspector(new SymfonyProcessRunner(65_536), binary: '/nonexistent/git-binary');

    $snapshot = $inspector->inspect($repo->path);

    expect($snapshot->state)->toBe(GitRepositoryState::Unavailable)
        ->and($snapshot->unavailableReason)->toBe('git_unavailable');
});

it('bounds Git output and reports an oversized answer as unavailable', function () {
    $repo = GitFixture::repository();

    // A cap far below even the status header.
    $snapshot = gitInspector(maxOutput: 8)->inspect($repo->path);

    expect($snapshot->state)->toBe(GitRepositoryState::Unavailable)
        ->and($snapshot->unavailableReason)->toBe('output_too_large');
});
