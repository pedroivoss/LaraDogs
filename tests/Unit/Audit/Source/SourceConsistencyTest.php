<?php

use App\Audit\Source\Git\GitRepositoryState;
use App\Audit\Source\Git\GitSnapshot;
use App\Audit\Source\Git\SourceConsistency;
use App\Audit\Source\SourceIntegrityReason as Reason;

function gsRepo(string $sha = 'a', ?string $branch = 'main', bool $detached = false, ?bool $dirty = false): GitSnapshot
{
    return new GitSnapshot(GitRepositoryState::Repository, str_repeat($sha, 40), $branch, $detached, $dirty);
}

function gsUnborn(bool $dirty = false): GitSnapshot
{
    return new GitSnapshot(GitRepositoryState::Repository, null, 'main', false, $dirty);
}

/** @return array{0: bool|null, 1: string|null} */
function gsAssess(GitSnapshot $before, GitSnapshot $after): array
{
    $integrity = SourceConsistency::assess($before, $after);

    return [$integrity->consistent, $integrity->reason?->value];
}

// ---------------- the source-integrity truth table ----------------

it('verifies only a clean Git repository with a commit that is identical before and after', function () {
    expect(gsAssess(gsRepo(), gsRepo()))->toBe([true, null]);
});

it('has no integrity claim for a genuine non-Git target', function () {
    expect(gsAssess(GitSnapshot::notRepository(), GitSnapshot::notRepository()))->toBe([null, null]);
});

it('is unverified when the commit, branch, detached state or dirty flag changes', function () {
    expect(gsAssess(gsRepo('a'), gsRepo('b')))->toBe([false, 'changed_during_audit'])
        ->and(gsAssess(gsRepo(branch: 'main'), gsRepo(branch: 'feature')))->toBe([false, 'changed_during_audit'])
        ->and(gsAssess(gsRepo(), gsRepo(branch: null, detached: true)))->toBe([false, 'changed_during_audit'])
        ->and(gsAssess(gsRepo(dirty: false), gsRepo(dirty: true)))->toBe([false, 'changed_during_audit'])
        ->and(gsAssess(gsRepo(dirty: true), gsRepo(dirty: false)))->toBe([false, 'changed_during_audit']);
});

it('is unverified when the kind of source changes', function () {
    expect(gsAssess(GitSnapshot::notRepository(), gsRepo()))->toBe([false, 'changed_during_audit'])
        ->and(gsAssess(gsRepo(), GitSnapshot::notRepository()))->toBe([false, 'changed_during_audit']);
});

it('does not trust a repository that was dirty at the start, even if it stayed dirty', function () {
    expect(gsAssess(gsRepo(dirty: true), gsRepo(dirty: true)))->toBe([false, 'dirty_at_start'])
        ->and(gsAssess(gsRepo(dirty: null), gsRepo(dirty: null)))->toBe([false, 'dirty_at_start']);
});

it('does not attest a repository without commits', function () {
    expect(gsAssess(gsUnborn(), gsUnborn()))->toBe([false, 'no_commits'])
        ->and(gsAssess(gsUnborn(dirty: true), gsUnborn(dirty: true)))->toBe([false, 'no_commits']);
});

it('does not attest a bare repository', function () {
    expect(gsAssess(GitSnapshot::bare(), GitSnapshot::bare()))->toBe([false, 'bare_repository']);
});

it('treats unreadable Git as unverified — never equivalent to "not a repository"', function () {
    expect(gsAssess(GitSnapshot::unavailable('timeout'), GitSnapshot::unavailable('timeout')))->toBe([false, 'unavailable'])
        ->and(gsAssess(gsRepo(), GitSnapshot::unavailable('git_error')))->toBe([false, 'unavailable'])
        ->and(gsAssess(GitSnapshot::unavailable('git_unavailable'), GitSnapshot::notRepository()))->toBe([false, 'unavailable'])
        ->and(gsAssess(GitSnapshot::notRepository(), GitSnapshot::unavailable('timeout')))->toBe([false, 'unavailable']);
});

it('distinguishes a refused configuration from other unreadable states', function () {
    expect(gsAssess(GitSnapshot::unavailable('unsafe_config'), GitSnapshot::unavailable('unsafe_config')))->toBe([false, 'unsafe_config'])
        ->and(gsAssess(gsRepo(), GitSnapshot::unavailable('unsafe_config')))->toBe([false, 'unsafe_config']);
});

it('only calls a demonstrated mutation "changed"; everything else is "could not be proven"', function () {
    expect(Reason::ChangedDuringAudit->isMutation())->toBeTrue()
        ->and(collect(Reason::cases())->reject(fn (Reason $r) => $r === Reason::ChangedDuringAudit)->every(fn (Reason $r) => ! $r->isMutation()))->toBeTrue()
        ->and(Reason::DirtyAtStart->explanation())->toBe('Source integrity could not be proven because the working tree was dirty when the audit began.')
        ->and(Reason::Unavailable->explanation())->toBe('Git source integrity could not be verified.')
        ->and(Reason::DirtyAtStart->explanation())->not->toContain('changed');
});

// ---------------- current vs last audited (dashboard) ----------------

it('compares current with last audited without over-claiming for dirty trees', function () {
    expect(SourceConsistency::sameSource(gsRepo(), gsRepo()))->toBeTrue()
        ->and(SourceConsistency::sameSource(gsRepo('a'), gsRepo('b')))->toBeFalse()
        ->and(SourceConsistency::sameSource(gsRepo(dirty: false), gsRepo(dirty: true)))->toBeFalse()
        ->and(SourceConsistency::sameSource(gsRepo(dirty: true), gsRepo(dirty: true)))->toBeNull()
        ->and(SourceConsistency::sameSource(GitSnapshot::notRepository(), GitSnapshot::notRepository()))->toBeNull()
        ->and(SourceConsistency::sameSource(gsRepo(), GitSnapshot::notRepository()))->toBeFalse();
});

it('treats a dirty tree or a missing commit as not reproducible from the SHA alone', function () {
    expect(gsRepo()->isReproducible())->toBeTrue()
        ->and(gsRepo(dirty: true)->isReproducible())->toBeFalse()
        ->and(gsUnborn()->isReproducible())->toBeFalse()
        ->and(GitSnapshot::notRepository()->isReproducible())->toBeFalse();
});

it('presents a 7 character short SHA while keeping the full SHA', function () {
    $snapshot = gsRepo('c');

    expect($snapshot->shortSha())->toBe('ccccccc')->and($snapshot->commitSha)->toHaveLength(40);
});
