<?php

use App\Audit\Projects\RunProjectAudit;
use App\Audit\QualityGates\Policy\MaxOpenFindingsRule;
use App\Audit\QualityGates\Policy\QualityGatePolicy;
use App\Audit\QualityGates\QualityGatePolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Git\GitFixture;
use Tests\Support\Git\GitProject;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    if (! GitFixture::available()) {
        $this->markTestSkipped('The git binary is not installed.');
    }
});

afterEach(fn () => GitFixture::cleanupAll());

// ---------------- laradogs:inspect ----------------

it('shows Git metadata in laradogs:inspect', function () {
    $p = GitProject::create();

    expect(Artisan::call('laradogs:inspect', ['path' => $p->repo->path]))->toBe(0);
    $out = Artisan::output();

    expect($out)->toContain('Git: detected')
        ->toContain('Branch: main')
        ->toContain('Revision: '.substr($p->repo->sha(), 0, 7))
        ->toContain('Working tree: clean');
});

it('reports a dirty tree and a non-Git directory in laradogs:inspect', function () {
    $p = GitProject::create();
    $p->repo->write('x.txt', 'x');
    Artisan::call('laradogs:inspect', ['path' => $p->repo->path]);
    expect(Artisan::output())->toContain('Working tree: dirty');

    $plain = GitProject::create(git: false);
    Artisan::call('laradogs:inspect', ['path' => $plain->repo->path]);
    expect(Artisan::output())->toContain('Git: not detected');
});

it('emits bounded Git metadata without credentials or local paths in laradogs:inspect --json', function () {
    $p = GitProject::create();
    $p->repo->git('remote', 'add', 'origin', 'https://deploy:ghp_TOPSECRET@example.com/org/app.git');

    Artisan::call('laradogs:inspect', ['path' => $p->repo->path, '--json' => true]);
    $raw = Artisan::output();
    $json = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($json['git']))->toBe(['type', 'commit', 'branch', 'detached', 'dirty', 'commit_at', 'commit_subject', 'remote'])
        ->and($json['git']['type'])->toBe('git')
        ->and($json['git']['commit'])->toBe($p->repo->sha())
        ->and($json['git']['remote'])->toBe('https://example.com/org/app.git')
        ->and($raw)->not->toContain('ghp_TOPSECRET')->not->toContain('deploy:')
        ->and($raw)->not->toContain('author-secret')->not->toContain('committer-secret');
});

// ---------------- laradogs:project:audit ----------------

it('displays the captured revision in laradogs:project:audit', function () {
    $p = GitProject::create();

    expect(Artisan::call('laradogs:project:audit', ['project' => $p->project->public_id]))->toBe(0);

    expect(Artisan::output())->toContain('Source: '.substr($p->repo->sha(), 0, 7).' · main · clean')
        ->not->toContain('changed while the audit was running');
});

it('warns explicitly when the source changed during a persisted audit', function () {
    $p = GitProject::create();
    $p->analyzer->whileRunning = fn () => $p->repo->write('mid.txt', 'x');

    Artisan::call('laradogs:project:audit', ['project' => $p->project->public_id]);

    expect(Artisan::output())->toContain('The source changed while the audit was running');
});

it('emits a bounded source block in laradogs:project:audit --json', function () {
    $p = GitProject::create();
    $p->repo->git('remote', 'add', 'origin', '/home/someone/private/app.git');

    Artisan::call('laradogs:project:audit', ['project' => $p->project->public_id, '--json' => true]);
    $raw = Artisan::output();
    $json = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

    expect($json['scan']['source'])->toBe([
        'type' => 'git',
        'commit' => $p->repo->sha(),
        'branch' => 'main',
        'detached' => false,
        'dirty' => false,
        'consistent' => true,
        'integrity_reason' => null,
    ])
        ->and($raw)->not->toContain('/home/someone')
        ->and($raw)->not->toContain($p->repo->path)
        ->and($raw)->not->toContain('@example.invalid');
});

it('reports consistent=false in the JSON source block when the source changed', function () {
    $p = GitProject::create();
    $p->analyzer->whileRunning = fn () => $p->repo->write('mid.txt', 'x');

    Artisan::call('laradogs:project:audit', ['project' => $p->project->public_id, '--json' => true]);
    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($json['scan']['source']['consistent'])->toBeFalse();
});

it('reports only the type for a non-Git project', function () {
    $p = GitProject::create(git: false);

    Artisan::call('laradogs:project:audit', ['project' => $p->project->public_id, '--json' => true]);
    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($json['scan']['source'])->toBe(['type' => 'none']);
});

it('shows the audited revision in the gate text output and leaves the JSON envelope untouched', function () {
    $p = GitProject::create();
    app(QualityGatePolicyService::class)->update(
        $p->project,
        true,
        new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]),
    );
    app(RunProjectAudit::class)->run($p->project);

    Artisan::call('laradogs:project:gate', ['project' => $p->project->public_id]);
    expect(Artisan::output())->toContain('Source: '.substr($p->repo->sha(), 0, 7).' · main · clean');

    Artisan::call('laradogs:project:gate', ['project' => $p->project->public_id, '--json' => true]);
    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($json))->toBe(['project', 'scan', 'gate', 'exit_code']);
});
