<?php

use App\Audit\Findings\ScanStatus;
use App\Audit\Findings\Severity;
use App\Audit\QualityGates\Policy\MaxOpenFindingsRule;
use App\Audit\QualityGates\Policy\NoNewSeverityRule;
use App\Audit\QualityGates\Policy\QualityGatePolicy;
use App\Models\Audit\ProjectActiveScan;
use App\Models\Audit\Scan;
use App\Models\Integrations\GitHubCheckReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Support\Git\GitFixture;
use Tests\Support\Git\GitProject;
use Tests\Support\QualityGates\GateScans;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    if (! GitFixture::available()) {
        $this->markTestSkipped('The git binary is not installed.');
    }
});

afterEach(fn () => GitFixture::cleanupAll());

/**
 * @return array{0: int, 1: array<string,mixed>}
 */
function ciRun(array $args): array
{
    $exit = Artisan::call('laradogs:ci:audit', [...$args, '--json' => true]);

    return [$exit, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)];
}

// ---------------- exit codes: the V1 contract ----------------

it('exits 0 for a clean revision with a Passed gate', function () {
    $p = GitProject::create();
    GateScans::enable($p->project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));

    [$exit, $json] = ciRun(['path' => $p->repo->path]);

    expect($exit)->toBe(0)
        ->and($json['gate']['outcome'])->toBe('passed')
        ->and($json['exit_code'])->toBe(0)
        ->and($json['error'])->toBeNull();
});

it('exits 1 for a Failed gate', function () {
    $p = GitProject::create();
    GateScans::enable($p->project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));
    $p->analyzer->candidates = [GateScans::candidate('bad', Severity::High)];

    [$exit, $json] = ciRun(['path' => $p->repo->path]);

    expect($exit)->toBe(1)->and($json['gate']['outcome'])->toBe('failed');
});

it('exits 2 for an Indeterminate gate', function () {
    $p = GitProject::create();
    GateScans::enable($p->project, new QualityGatePolicy([new NoNewSeverityRule(Severity::High)]));

    [$exit, $json] = ciRun(['path' => $p->repo->path]);

    expect($exit)->toBe(2)->and($json['gate']['outcome'])->toBe('indeterminate');
});

it('exits 3 for an operational failure (an invalid project path)', function () {
    [$exit, $json] = ciRun(['path' => '/definitely/not/a/real/laradogs/path']);

    expect($exit)->toBe(3)
        ->and($json['gate'])->toBeNull()
        ->and($json['scan'])->toBeNull()
        ->and($json['error'])->not->toBeNull();
});

it('exits 4 when the gate was never evaluated (no policy configured)', function () {
    $p = GitProject::create();

    [$exit, $json] = ciRun(['path' => $p->repo->path]);

    expect($exit)->toBe(4)->and($json['gate'])->toBeNull()->and($json['scan'])->not->toBeNull();
});

it('exits 3 when another audit is already active for the project', function () {
    $p = GitProject::create();
    $running = Scan::query()->create(['project_id' => $p->project->id, 'status' => ScanStatus::Running, 'started_at' => now(), 'project_profile' => []]);
    ProjectActiveScan::query()->create(['project_id' => $p->project->id, 'scan_id' => $running->id]);

    [$exit, $json] = ciRun(['path' => $p->repo->path]);

    expect($exit)->toBe(3)->and($json['error'])->toContain('already');
});

// ---------------- revision verification ----------------

it('proceeds normally when the expected revision matches', function () {
    $p = GitProject::create();
    GateScans::enable($p->project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));

    [$exit, $json] = ciRun(['path' => $p->repo->path, '--expected-revision' => $p->repo->sha()]);

    expect($exit)->toBe(0)
        ->and($json['ci']['expected_revision'])->toBe($p->repo->sha())
        ->and($json['ci']['revision_verified'])->toBeTrue();
});

it('exits 3 on an expected-revision mismatch BEFORE running the audit — no scan is created', function () {
    $p = GitProject::create();
    $wrong = str_repeat('b', 40);

    [$exit, $json] = ciRun(['path' => $p->repo->path, '--expected-revision' => $wrong]);

    expect($exit)->toBe(3)
        ->and($json['ci']['revision_verified'])->toBeFalse()
        ->and($json['scan'])->toBeNull()
        ->and(Scan::query()->count())->toBe(0);
});

it('still verifies the expected revision when the source changes DURING the audit — that is a separate, Phase 9.1 integrity concern (Indeterminate), not a revision mismatch', function () {
    // The scan's OWN `source_revision` is always the snapshot captured
    // BEFORE analyzers ran (Phase 9), so it still equals what CI expected —
    // the mid-audit mutation is caught by `source_consistent`/the gate
    // going Indeterminate, never mislabeled as auditing "the wrong commit".
    $p = GitProject::create();
    GateScans::enable($p->project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));
    $expected = $p->repo->sha();
    $p->analyzer->whileRunning = function () use ($p) {
        $p->repo->write('mid-audit.txt', 'changed while analyzers ran');
        $p->repo->commitAll('changed mid-audit');
    };

    [$exit, $json] = ciRun(['path' => $p->repo->path, '--expected-revision' => $expected]);

    expect($exit)->toBe(2)
        ->and($json['ci']['revision_verified'])->toBeTrue()
        ->and($json['scan']['source']['commit'])->toBe($expected)
        ->and($json['scan']['source']['consistent'])->toBeFalse()
        ->and($json['gate']['outcome'])->toBe('indeterminate')
        ->and(Scan::query()->count())->toBe(1);
});

it('never reports a gate verdict to GitHub for a genuinely wrong starting commit (caught by the pre-audit check)', function () {
    Http::fake(['api.github.com/*' => Http::response(['id' => 1], 201)]);
    $p = GitProject::create();
    GateScans::enable($p->project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));
    $wrong = str_repeat('d', 40);

    [$exit, $json] = ciRun(['path' => $p->repo->path, '--expected-revision' => $wrong, '--github-report' => true]);

    expect($exit)->toBe(3)
        ->and($json['scan'])->toBeNull(); // no audit ever ran for the wrong commit
    Http::assertNothingSent(); // nothing to attach a Check Run to — never a fabricated report
});

it('accepts an uppercase --expected-revision and normalizes it', function () {
    $p = GitProject::create();

    [$exit, $json] = ciRun(['path' => $p->repo->path, '--expected-revision' => strtoupper($p->repo->sha())]);

    expect($json['ci']['revision_verified'])->toBeTrue();
});

it('auto-detects GITHUB_SHA as the expected revision under GITHUB_ACTIONS=true', function () {
    $p = GitProject::create();
    putenv('GITHUB_ACTIONS=true');
    putenv('GITHUB_SHA='.$p->repo->sha());

    try {
        [$exit, $json] = ciRun(['path' => $p->repo->path]);
    } finally {
        putenv('GITHUB_ACTIONS');
        putenv('GITHUB_SHA');
    }

    expect($json['ci']['expected_revision'])->toBe($p->repo->sha())
        ->and($json['ci']['revision_verified'])->toBeTrue();
});

it('lets an explicit --expected-revision win over GITHUB_SHA', function () {
    $p = GitProject::create();
    putenv('GITHUB_ACTIONS=true');
    putenv('GITHUB_SHA='.str_repeat('c', 40));

    try {
        [$exit, $json] = ciRun(['path' => $p->repo->path, '--expected-revision' => $p->repo->sha()]);
    } finally {
        putenv('GITHUB_ACTIONS');
        putenv('GITHUB_SHA');
    }

    expect($json['ci']['expected_revision'])->toBe($p->repo->sha())
        ->and($json['ci']['revision_verified'])->toBeTrue();
});

it('does not auto-detect GITHUB_SHA when GITHUB_ACTIONS is not true', function () {
    $p = GitProject::create();
    putenv('GITHUB_SHA='.$p->repo->sha()); // GITHUB_ACTIONS deliberately not set

    try {
        [$exit, $json] = ciRun(['path' => $p->repo->path]);
    } finally {
        putenv('GITHUB_SHA');
    }

    expect($json['ci']['expected_revision'])->toBeNull()
        ->and($json['ci']['revision_verified'])->toBeNull();
});

// ---------------- detached HEAD / dirty source ----------------

it('audits a detached HEAD normally — the full SHA is canonical, no branch required', function () {
    $p = GitProject::create();
    $sha = $p->repo->sha();
    $p->repo->detach();
    GateScans::enable($p->project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));

    [$exit, $json] = ciRun(['path' => $p->repo->path, '--expected-revision' => $sha]);

    expect($exit)->toBe(0)
        ->and($json['ci']['revision_verified'])->toBeTrue()
        ->and($json['scan']['source']['detached'])->toBeTrue()
        ->and($json['scan']['source']['branch'])->toBeNull();
});

it('cannot false-pass an absence-based rule when the CI worktree is dirty', function () {
    $p = GitProject::create();
    GateScans::enable($p->project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));
    $p->repo->write('uncommitted.txt', 'dirty ci checkout');

    [$exit, $json] = ciRun(['path' => $p->repo->path]);

    expect($exit)->toBe(2)
        ->and($json['gate']['outcome'])->toBe('indeterminate')
        ->and($json['scan']['source']['dirty'])->toBeTrue();
});

// ---------------- JSON envelope ----------------

it('exposes a stable, bounded JSON envelope', function () {
    $p = GitProject::create();
    GateScans::enable($p->project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));

    [, $json] = ciRun(['path' => $p->repo->path, '--expected-revision' => $p->repo->sha()]);

    expect(array_keys($json))->toBe(['project', 'scan', 'ci', 'gate', 'github', 'exit_code', 'error'])
        ->and(array_keys($json['project']))->toBe(['id', 'name'])
        ->and(array_keys($json['scan']))->toBe(['id', 'status', 'source'])
        ->and($json['scan']['source']['commit'])->toBe($p->repo->sha())
        ->and($json['scan']['source']['consistent'])->toBeTrue()
        ->and(array_keys($json['gate']))->toBe(['outcome', 'policy_revision', 'evaluated_at', 'baseline_scan', 'rules_total', 'rules_failed', 'rules_indeterminate', 'rules'])
        ->and($json['github'])->toBeNull(); // --github-report was not requested
});

it('never leaks a host path anywhere in the JSON envelope', function () {
    $p = GitProject::create();
    GateScans::enable($p->project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));

    [, $json] = ciRun(['path' => $p->repo->path]);

    expect(json_encode($json))->not->toContain($p->repo->path)->not->toContain(sys_get_temp_dir());
});

// ---------------- GitHub reporting wiring ----------------

it('includes a github block only when --github-report is requested', function () {
    Http::fake(['api.github.com/*' => Http::response(['id' => 42, 'html_url' => 'https://github.com/o/r/runs/42'], 201)]);
    putenv('GITHUB_TOKEN=ghs_fakeTokenForTests');
    $p = GitProject::create();
    GateScans::enable($p->project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));

    try {
        [$exit, $json] = ciRun([
            'path' => $p->repo->path,
            '--github-report' => true,
        ]);
    } finally {
        putenv('GITHUB_TOKEN');
    }

    // No GITHUB_REPOSITORY context in this test process -> cannot report,
    // but the block must still be present and MUST NOT change the exit code.
    expect($exit)->toBe(0)
        ->and($json['github'])->not->toBeNull()
        ->and($json['github']['reported'])->toBeFalse()
        ->and($json['github']['reason'])->toBe('no_github_context');
});

it('reports to GitHub end-to-end and never changes the exit code on API failure', function () {
    Http::fake(['api.github.com/*' => Http::response('server error', 500)]);
    putenv('GITHUB_ACTIONS=true');
    putenv('GITHUB_REPOSITORY=pedroivoss/LaraDogs');
    putenv('GITHUB_TOKEN=ghs_fakeTokenForTests');
    $p = GitProject::create();
    GateScans::enable($p->project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));

    try {
        [$exit, $json] = ciRun(['path' => $p->repo->path, '--github-report' => true]);
    } finally {
        putenv('GITHUB_ACTIONS');
        putenv('GITHUB_REPOSITORY');
        putenv('GITHUB_TOKEN');
    }

    expect($exit)->toBe(0) // gate Passed — the API failure never touches this
        ->and($json['github']['reported'])->toBeFalse()
        ->and(GitHubCheckReport::query()->count())->toBe(0);
});

it('never leaks the GitHub token anywhere in the JSON envelope', function () {
    Http::fake(['api.github.com/*' => Http::response(['id' => 42, 'html_url' => 'https://github.com/o/r/runs/42'], 201)]);
    putenv('GITHUB_ACTIONS=true');
    putenv('GITHUB_REPOSITORY=pedroivoss/LaraDogs');
    putenv('GITHUB_TOKEN=ghs_SuperSecretTestToken');
    $p = GitProject::create();
    GateScans::enable($p->project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));

    try {
        [, $json] = ciRun(['path' => $p->repo->path]);
    } finally {
        putenv('GITHUB_ACTIONS');
        putenv('GITHUB_REPOSITORY');
        putenv('GITHUB_TOKEN');
    }

    expect(json_encode($json))->not->toContain('ghs_SuperSecretTestToken');
});
