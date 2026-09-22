<?php

use App\Audit\Engine\Process\SymfonyProcessRunner;
use App\Audit\Projects\RunProjectAudit;
use App\Audit\Source\Git\GitRepositoryInspector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Git\GitFixture;
use Tests\Support\Git\GitProject;
use Tests\Support\Process\RecordingProcessRunner;
use Tests\Support\QualityGates\GateScans;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    if (! GitFixture::available()) {
        $this->markTestSkipped('The git binary is not installed.');
    }

    $this->user = User::factory()->create();
});

afterEach(fn () => GitFixture::cleanupAll());

/** Binds an inspector whose Git commands are recorded, to count subprocesses. */
function srcRecorded(): RecordingProcessRunner
{
    $recorder = new RecordingProcessRunner(new SymfonyProcessRunner(65_536));
    app()->instance(GitRepositoryInspector::class, new GitRepositoryInspector($recorder, home: sys_get_temp_dir().'/laradogs-no-home'));

    return $recorder;
}

// ---------------- Project Detail ----------------

it('shows the current Git state on Project Detail', function () {
    $p = GitProject::create();

    $this->actingAs($this->user)->get(route('projects.show', $p->project))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('source.current.type', 'git')
            ->where('source.current.commit', $p->repo->sha())
            ->where('source.current.short_commit', substr($p->repo->sha(), 0, 7))
            ->where('source.current.branch', 'main')
            ->where('source.current.dirty', false)
            ->where('source.last_audited', null)
            ->where('source.changed_since_last_audit', null));
});

it('shows the last audited revision next to the current one and detects a change', function () {
    $p = GitProject::create();
    $result = app(RunProjectAudit::class)->run($p->project);
    $auditedSha = $p->repo->sha();

    $this->actingAs($this->user)->get(route('projects.show', $p->project))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('source.last_audited.scan_id', $result->scan->public_id)
            ->where('source.last_audited.source.commit', $auditedSha)
            ->where('source.last_audited.source.consistent', true)
            ->where('source.changed_since_last_audit', false));

    $p->repo->write('later.txt', 'more');
    $newSha = $p->repo->commitAll('Later');

    $this->actingAs($this->user)->get(route('projects.show', $p->project))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('source.current.commit', $newSha)
            ->where('source.last_audited.source.commit', $auditedSha)
            ->where('source.changed_since_last_audit', true));
});

it('flags a dirty working tree as a change since the last audit', function () {
    $p = GitProject::create();
    app(RunProjectAudit::class)->run($p->project);
    $p->repo->write('README.local', 'wip');

    $this->actingAs($this->user)->get(route('projects.show', $p->project))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('source.current.dirty', true)
            ->where('source.changed_since_last_audit', true));
});

it('renders a legacy last audit without inventing a revision', function () {
    $project = GateScans::project();
    GateScans::scan($project); // pre-Phase-9 scan: no snapshot

    $this->actingAs($this->user)->get(route('projects.show', $project))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('source.last_audited.source', null)
            ->where('source.changed_since_last_audit', null));
});

it('reports a non-Git project as such and never a change', function () {
    $p = GitProject::create(git: false);
    app(RunProjectAudit::class)->run($p->project);

    $this->actingAs($this->user)->get(route('projects.show', $p->project))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('source.current.type', 'none')
            ->where('source.last_audited.source.type', 'none')
            ->where('source.changed_since_last_audit', null));
});

// ---------------- Scan History / Scan Detail ----------------

it('shows compact revision information in Scan History', function () {
    $p = GitProject::create();
    $p->repo->write('wip.txt', 'x');
    app(RunProjectAudit::class)->run($p->project);

    $this->actingAs($this->user)->get(route('projects.scans', $p->project))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('scans.0.source.short_commit', substr($p->repo->sha(), 0, 7))
            ->where('scans.0.source.branch', 'main')
            ->where('scans.0.source.dirty', true)
            // Dirty at the start: integrity could not be PROVEN (not "changed").
            ->where('scans.0.source.consistent', false)
            ->where('scans.0.source.integrity_reason', 'dirty_at_start')
            ->where('scans.0.source.integrity_changed', false));
});

it('shows the immutable source snapshot in Scan Detail without credentials, author or host paths', function () {
    $p = GitProject::create();
    $p->repo->git('remote', 'add', 'origin', 'https://deploy:ghp_TOPSECRET@example.com/org/app.git');
    $scan = app(RunProjectAudit::class)->run($p->project)->scan;

    $response = $this->actingAs($this->user)->get(route('projects.scans.show', [$p->project, $scan]));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('scan.source.commit', $p->repo->sha())
        ->where('scan.source.branch', 'main')
        ->where('scan.source.detached', false)
        ->where('scan.source.dirty', false)
        ->where('scan.source.consistent', true)
        ->where('scan.source.commit_subject', 'Fixture project')
        ->where('scan.source.remote', 'https://example.com/org/app.git')
        ->has('scan.source.commit_at'));

    // The source block itself (the project_profile snapshot pre-dates this
    // phase and is not part of it).
    $source = json_encode($response->viewData('page')['props']['scan']['source'], JSON_THROW_ON_ERROR);
    $body = $response->getContent();

    expect($source)->not->toContain($p->repo->path)->not->toContain(dirname($p->repo->path))
        ->and($body)->not->toContain('ghp_TOPSECRET')
        ->not->toContain('author-secret')
        ->not->toContain('committer-secret')
        ->not->toContain('Fixture Author');
});

it('renders a legacy scan detail with null source', function () {
    $project = GateScans::project();
    $scan = GateScans::scan($project);

    $this->actingAs($this->user)->get(route('projects.scans.show', [$project, $scan]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('scan.source', null));
});

// ---------------- performance: bounded Git subprocesses ----------------

it('runs Git only for Project Detail — never per row on the list, history or scan detail', function () {
    $recorder = srcRecorded();
    $projects = [GitProject::create(), GitProject::create(), GitProject::create()];
    $scan = app(RunProjectAudit::class)->run($projects[0]->project)->scan;
    $recorder->commands = []; // ignore the audit itself

    $this->actingAs($this->user)->get(route('projects.index'))->assertOk();
    $this->actingAs($this->user)->get(route('projects.scans', $projects[0]->project))->assertOk();
    $this->actingAs($this->user)->get(route('projects.scans.show', [$projects[0]->project, $scan]))->assertOk();

    expect($recorder->commands)->toBe([]);
});

it('runs a constant, small number of Git commands on Project Detail and none on an active-scan poll', function () {
    $recorder = srcRecorded();
    $p = GitProject::create();

    $this->actingAs($this->user)->get(route('projects.show', $p->project))->assertOk();
    $fullLoad = count($recorder->commands);

    expect($fullLoad)->toBeGreaterThan(0)->toBeLessThanOrEqual(4);

    $recorder->commands = [];

    $this->actingAs($this->user)->get(route('projects.show', $p->project))
        ->assertInertia(fn (AssertableInertia $page) => $page->reloadOnly(['active_scan', 'summary', 'recent_scans']));

    // reloadOnly performs the partial request; `source` was not requested.
    expect(count($recorder->commands))->toBe($fullLoad); // only the initial full load ran Git again
});
