<?php

use App\Audit\Ci\CiOutcome;
use App\Audit\Engine\Process\SymfonyProcessRunner;
use App\Audit\QualityGates\Policy\MaxOpenFindingsRule;
use App\Audit\QualityGates\Policy\QualityGatePolicy;
use App\Audit\Source\Git\GitRepositoryInspector;
use App\Integrations\GitHub\GitHubContext;
use App\Integrations\GitHub\RecordGitHubCheckRun;
use App\Models\Audit\QualityGateResult;
use App\Models\Audit\Scan;
use App\Models\Integrations\GitHubCheckReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Support\Git\GitFixture;
use Tests\Support\Git\GitProject;
use Tests\Support\Process\HookedProcessRunner;
use Tests\Support\QualityGates\GateScans;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * Phase 10.1 — DETERMINISTIC post-audit mismatch. No timing, no race:
 *
 *   Git inspection #1 = the CI pre-audit check          -> sees revision A (matches --expected-revision)
 *   Git inspection #2 = the audit's own before-snapshot -> a hook has moved the repository to B
 *
 * so the persisted `scan.source_revision` is B while CI expected A: the
 * authoritative post-audit check fails while the Quality Gate (a clean,
 * consistent audit of B) honestly Passed. This drives the REAL command, the
 * REAL ScanRunner and the REAL reporter — nothing about the orchestration is
 * stubbed; only the moment the repository moves is scripted.
 */

beforeEach(function () {
    if (! GitFixture::available()) {
        $this->markTestSkipped('The git binary is not installed.');
    }

    putenv('GITHUB_ACTIONS=true');
    putenv('GITHUB_REPOSITORY=pedroivoss/LaraDogs');
    putenv('GITHUB_TOKEN=ghs_fakeTokenForPostAuditTests');
});

afterEach(function () {
    putenv('GITHUB_ACTIONS');
    putenv('GITHUB_REPOSITORY');
    putenv('GITHUB_TOKEN');
    putenv('GITHUB_SHA');
    GitFixture::cleanupAll();
});

/**
 * @return array{0: GitProject, 1: string, 2: string} project, revision A, revision B
 */
function pamScenario(): array
{
    $p = GitProject::create();
    GateScans::enable($p->project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));
    $a = $p->repo->sha();
    $b = null;

    app()->instance(GitRepositoryInspector::class, new GitRepositoryInspector(
        new HookedProcessRunner(new SymfonyProcessRunner(65_536), function (int $inspection) use ($p, &$b): void {
            if ($inspection === 2) {
                $p->repo->write('moved-to-b.txt', 'the repository moved between the pre-check and the audit');
                $b = $p->repo->commitAll('revision B');
            }
        }),
        home: sys_get_temp_dir().'/laradogs-no-home',
    ));

    // By reference: `$b` is only known once the hook has fired during the audit.
    return [$p, $a, function () use (&$b) {
        return $b;
    }];
}

function pamRun(GitProject $p, string $expected, array $extra = []): array
{
    $exit = Artisan::call('laradogs:ci:audit', ['path' => $p->repo->path, '--expected-revision' => $expected, '--json' => true, ...$extra]);

    return [$exit, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)];
}

it('exits 3 (process AND JSON) when the persisted revision no longer matches, even though the gate Passed', function () {
    [$p, $a, $b] = pamScenario();

    [$exit, $json] = pamRun($p, $a);

    expect($exit)->toBe(3)
        ->and($json['exit_code'])->toBe(3)
        ->and($json['ci']['revision_verified'])->toBeFalse()
        ->and($json['scan']['source']['commit'])->toBe($b()) // the scan truthfully records what was audited
        ->and($json['scan']['source']['commit'])->not->toBe($a)
        ->and($json['gate']['outcome'])->toBe('passed') // the gate fact is reported truthfully, never rewritten
        ->and($json['error'])->toContain('does not match the expected revision');
});

it('reports a FAILURE Check Run (never success) against the actual audited SHA', function () {
    Http::fake(['api.github.com/*' => Http::response(['id' => 900, 'html_url' => 'https://github.com/o/r/runs/900'], 201)]);
    [$p, $a, $b] = pamScenario();

    [$exit, $json] = pamRun($p, $a, ['--github-report' => true]);

    expect($exit)->toBe(3)->and($json['github']['reported'])->toBeTrue();
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['conclusion'] === 'failure'
        && $request['head_sha'] === $b()
        && $request['head_sha'] !== $a
        && str_contains((string) $request['output']['summary'], 'Operational error'));
    Http::assertNotSent(fn ($request) => $request['conclusion'] === 'success');
});

it('never mutates the persisted Scan or Quality Gate result to manufacture the failure', function () {
    Http::fake(['api.github.com/*' => Http::response(['id' => 901], 201)]);
    [$p, $a, $b] = pamScenario();

    pamRun($p, $a, ['--github-report' => true]);

    $scan = Scan::query()->firstOrFail();
    $gate = QualityGateResult::query()->firstOrFail();

    expect(Scan::query()->count())->toBe(1)
        ->and($scan->status->value)->toBe('completed')
        ->and($scan->source_revision)->toBe($b())
        ->and($scan->source_consistent)->toBeTrue()
        ->and($gate->outcome->value)->toBe('passed')
        ->and($gate->rules_failed)->toBe(0)
        ->and(QualityGateResult::query()->count())->toBe(1);
});

it('keeps exit 3 and reports reported=false when the GitHub API fails', function () {
    Http::fake(['api.github.com/*' => Http::response('server error', 500)]);
    [$p, $a] = pamScenario();

    [$exit, $json] = pamRun($p, $a, ['--github-report' => true]);

    expect($exit)->toBe(3)
        ->and($json['exit_code'])->toBe(3)
        ->and($json['github']['reported'])->toBeFalse()
        ->and(GitHubCheckReport::query()->count())->toBe(0)
        ->and(QualityGateResult::query()->firstOrFail()->outcome->value)->toBe('passed');
});

it('makes the operational-failure Check idempotent: a second report for the same scan creates no second Check Run', function () {
    Http::fake(['api.github.com/*' => Http::response(['id' => 902], 201)]);
    [$p, $a] = pamScenario();

    pamRun($p, $a, ['--github-report' => true]);
    $scan = Scan::query()->firstOrFail();
    $again = app(RecordGitHubCheckRun::class)->record(
        $scan,
        CiOutcome::OperationalError,
        GitHubContext::fromEnvironment(['GITHUB_ACTIONS' => 'true', 'GITHUB_REPOSITORY' => 'pedroivoss/LaraDogs']),
        'ghs_fakeTokenForPostAuditTests',
    );

    expect($again->reason)->toBe('already_reported')
        ->and(GitHubCheckReport::query()->count())->toBe(1);
    Http::assertSentCount(1);
});

it('keeps the whole contract intact for a matching run under the same hook harness (exit 0, success)', function () {
    Http::fake(['api.github.com/*' => Http::response(['id' => 903], 201)]);
    $p = GitProject::create();
    GateScans::enable($p->project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));
    $a = $p->repo->sha();

    [$exit, $json] = pamRun($p, $a, ['--github-report' => true]);

    expect($exit)->toBe(0)->and($json['ci']['revision_verified'])->toBeTrue();
    Http::assertSent(fn ($request) => $request['conclusion'] === 'success' && $request['head_sha'] === $a);
});

// ---------------- generic CI: no GitHub API without --github-report ----------------

it('performs no GitHub HTTP request and sends no Authorization header without --github-report, even with a token in the environment', function () {
    Http::fake();
    $p = GitProject::create();
    GateScans::enable($p->project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));
    putenv('GITHUB_SHA='.$p->repo->sha());

    [$exit, $json] = pamRun($p, $p->repo->sha());

    expect($exit)->toBe(0)->and($json['github'])->toBeNull();
    Http::assertNothingSent();
});

it('still applies the GITHUB_SHA default expected revision without --github-report (validated context only, no API)', function () {
    Http::fake();
    $p = GitProject::create();
    putenv('GITHUB_SHA='.$p->repo->sha());

    $exit = Artisan::call('laradogs:ci:audit', ['path' => $p->repo->path, '--json' => true]);
    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($json['ci']['expected_revision'])->toBe($p->repo->sha())
        ->and($json['ci']['revision_verified'])->toBeTrue()
        ->and($json['github'])->toBeNull();
    Http::assertNothingSent();
});
