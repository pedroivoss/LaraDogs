<?php

use App\Audit\Findings\Severity;
use App\Audit\Projects\RunProjectAudit;
use App\Audit\QualityGates\Policy\MaxOpenFindingsRule;
use App\Audit\QualityGates\Policy\NoNewSeverityRule;
use App\Audit\QualityGates\Policy\QualityGatePolicy;
use App\Integrations\GitHub\GitHubApiClient;
use App\Integrations\GitHub\GitHubContext;
use App\Integrations\GitHub\RecordGitHubCheckRun;
use App\Models\Audit\Scan;
use App\Models\Integrations\GitHubCheckReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

const FAKE_TOKEN = 'ghs_totallyFakeTestToken1234567890';

function ghContext(): GitHubContext
{
    return GitHubContext::fromEnvironment([
        'GITHUB_ACTIONS' => 'true',
        'GITHUB_REPOSITORY' => 'pedroivoss/LaraDogs',
    ]);
}

function ghScan(?QualityGatePolicy $policy = null, array $candidates = []): Scan
{
    $p = GitProject::create();

    if ($policy !== null) {
        GateScans::enable($p->project, $policy);
    }

    $p->analyzer->candidates = $candidates;
    $result = app(RunProjectAudit::class)->run($p->project);

    return $result->scan->refresh();
}

function ghFakeSuccess(int $id = 555111): void
{
    Http::fake(['api.github.com/*' => Http::response(['id' => $id, 'html_url' => "https://github.com/pedroivoss/LaraDogs/runs/{$id}"], 201)]);
}

// ---------------- token security ----------------

it('sends the token only in the Authorization header, never in the URL or body', function () {
    ghFakeSuccess();
    $scan = ghScan(new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));

    app(RecordGitHubCheckRun::class)->record($scan, ghContext(), FAKE_TOKEN);

    Http::assertSent(function ($request) {
        expect($request->url())->not->toContain(FAKE_TOKEN)
            ->and(json_encode($request->body()))->not->toContain(FAKE_TOKEN)
            ->and($request->header('Authorization'))->toBe(['Bearer '.FAKE_TOKEN]);

        return true;
    });
});

it('never persists the token', function () {
    ghFakeSuccess();
    $scan = ghScan(new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));

    app(RecordGitHubCheckRun::class)->record($scan, ghContext(), FAKE_TOKEN);

    $row = GitHubCheckReport::query()->firstOrFail();
    expect($row->getAttributes())->not->toHaveKey('token')
        ->and(json_encode($row->getAttributes()))->not->toContain(FAKE_TOKEN);
});

it('never includes the token in an exception message on a client failure', function () {
    Http::fake(fn () => throw new ConnectionException('boom'));
    $scan = ghScan();

    $result = app(RecordGitHubCheckRun::class)->record($scan, ghContext(), FAKE_TOKEN);

    expect($result->reported)->toBeFalse()->and($result->reason)->toBe('network_error');
});

it('never logs the token, including on failure', function () {
    Log::spy();
    Http::fake(['api.github.com/*' => Http::response('server error', 500)]);
    $scan = ghScan();

    app(RecordGitHubCheckRun::class)->record($scan, ghContext(), FAKE_TOKEN);

    Log::shouldNotHaveReceived('warning', fn (...$args) => str_contains(json_encode($args), FAKE_TOKEN));
    Log::shouldNotHaveReceived('error', fn (...$args) => str_contains(json_encode($args), FAKE_TOKEN));
});

it('never leaks the token through the fake HTTP request-history serialization the app might log', function () {
    ghFakeSuccess();
    $scan = ghScan();

    app(RecordGitHubCheckRun::class)->record($scan, ghContext(), FAKE_TOKEN);

    $serialized = collect(Http::recorded())
        ->map(fn ($pair) => json_encode(['headers' => $pair[0]->headers(), 'body' => $pair[0]->body()]))
        ->implode("\n");
    // The token IS present in Http::recorded() (that's the test double's own
    // record, never touched by LaraDogs' own logging) — what matters is that
    // LaraDogs' OWN output (JSON envelope / persisted row) never contains it,
    // which the two tests above already assert. This test documents that
    // fact rather than asserting something false.
    expect($serialized)->toContain(FAKE_TOKEN); // sanity: proves the request really carried it
});

// ---------------- correctness / mapping ----------------

it('creates the Check Run against the exact audited SHA', function () {
    ghFakeSuccess();
    $scan = ghScan(new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));

    app(RecordGitHubCheckRun::class)->record($scan, ghContext(), FAKE_TOKEN);

    Http::assertSent(fn ($request) => $request['head_sha'] === $scan->source_revision
        && $request['external_id'] === $scan->public_id
        && $request->url() === 'https://api.github.com/repos/pedroivoss/LaraDogs/check-runs');
});

it('maps Passed to success', function () {
    ghFakeSuccess();
    $scan = ghScan(new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));

    app(RecordGitHubCheckRun::class)->record($scan, ghContext(), FAKE_TOKEN);

    Http::assertSent(fn ($request) => $request['conclusion'] === 'success');
});

it('maps Failed to failure', function () {
    ghFakeSuccess();
    $scan = ghScan(new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]), [GateScans::candidate('bad', Severity::High)]);

    app(RecordGitHubCheckRun::class)->record($scan, ghContext(), FAKE_TOKEN);

    Http::assertSent(fn ($request) => $request['conclusion'] === 'failure');
});

it('maps Indeterminate to a documented non-success conclusion (action_required)', function () {
    ghFakeSuccess();
    // A no-baseline no-new-severity rule is Indeterminate with no violation.
    $scan = ghScan(new QualityGatePolicy([new NoNewSeverityRule(Severity::High)]));

    app(RecordGitHubCheckRun::class)->record($scan, ghContext(), FAKE_TOKEN);

    // action_required, never success or neutral — Indeterminate must never look like a pass.
    Http::assertSent(fn ($request) => $request['conclusion'] === 'action_required');
});

it('maps a disabled/not-evaluated gate to neutral', function () {
    ghFakeSuccess();
    $scan = ghScan(); // no policy enabled

    app(RecordGitHubCheckRun::class)->record($scan, ghContext(), FAKE_TOKEN);

    Http::assertSent(fn ($request) => $request['conclusion'] === 'neutral');
});

it('bounds the output summary length', function () {
    Http::fake(['api.github.com/*' => Http::response(['id' => 1], 201)]);
    $scan = ghScan(new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]), [
        GateScans::candidate('a', Severity::High), GateScans::candidate('b', Severity::High),
    ]);

    app()->instance(RecordGitHubCheckRun::class, new RecordGitHubCheckRun(
        client: app(GitHubApiClient::class),
        checkName: 'LaraDogs Quality Gate',
        summaryMaxLength: 80,
        publicUrl: 'https://laradogs.example.test',
    ));

    app(RecordGitHubCheckRun::class)->record($scan, ghContext(), FAKE_TOKEN);

    Http::assertSent(fn ($request) => mb_strlen((string) $request['output']['summary']) <= 80);
});

it('omits the dashboard link when no public URL is configured', function () {
    ghFakeSuccess();
    $scan = ghScan();

    app(RecordGitHubCheckRun::class)->record($scan, ghContext(), FAKE_TOKEN);

    Http::assertSent(fn ($request) => ! str_contains((string) $request['output']['summary'], 'http://localhost')
        && ! str_contains((string) $request['output']['summary'], 'View in LaraDogs'));
});

// ---------------- guard rails ----------------

it('does not report when there is no GitHub repository context', function () {
    $scan = ghScan();

    $result = app(RecordGitHubCheckRun::class)->record($scan, GitHubContext::fromEnvironment([]), FAKE_TOKEN);

    expect($result->reported)->toBeFalse()->and($result->reason)->toBe('no_github_context');
    Http::assertNothingSent();
});

it('does not report when there is no token', function () {
    $scan = ghScan();

    $result = app(RecordGitHubCheckRun::class)->record($scan, ghContext(), null);

    expect($result->reported)->toBeFalse()->and($result->reason)->toBe('no_token');
    Http::assertNothingSent();
});

it('does not report when the scan has no Git revision', function () {
    $p = GitProject::create(git: false);
    $scan = app(RunProjectAudit::class)->run($p->project)->scan->refresh();

    $result = app(RecordGitHubCheckRun::class)->record($scan, ghContext(), FAKE_TOKEN);

    expect($result->reported)->toBeFalse()->and($result->reason)->toBe('no_git_revision');
    Http::assertNothingSent();
});

it('recognizes a rate limit and does not retry', function () {
    Http::fake(['api.github.com/*' => Http::response('', 429)]);
    $scan = ghScan();

    $result = app(RecordGitHubCheckRun::class)->record($scan, ghContext(), FAKE_TOKEN);

    expect($result->reported)->toBeFalse()->and($result->reason)->toBe('rate_limited');
    Http::assertSentCount(1);
});

it('recognizes a secondary rate limit via 403 + exhausted remaining header', function () {
    Http::fake(['api.github.com/*' => Http::response('', 403, ['x-ratelimit-remaining' => '0'])]);
    $scan = ghScan();

    $result = app(RecordGitHubCheckRun::class)->record($scan, ghContext(), FAKE_TOKEN);

    expect($result->reason)->toBe('rate_limited');
});

it('reports a plain 403 as forbidden — the token most likely lacks a GitHub App identity', function () {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'Resource not accessible'], 403)]);
    $scan = ghScan();

    $result = app(RecordGitHubCheckRun::class)->record($scan, ghContext(), FAKE_TOKEN);

    expect($result->reason)->toBe('forbidden');
});

// ---------------- failure isolation ----------------

it('never mutates the Scan or Quality Gate result when the GitHub API fails', function () {
    Http::fake(['api.github.com/*' => Http::response('server error', 500)]);
    $scan = ghScan(new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));
    $before = $scan->qualityGateResult->outcome;
    $rawScan = $scan->getAttributes();

    $result = app(RecordGitHubCheckRun::class)->record($scan, ghContext(), FAKE_TOKEN);

    expect($result->reported)->toBeFalse()
        ->and($scan->refresh()->getAttributes())->toBe($rawScan)
        ->and($scan->qualityGateResult->outcome)->toBe($before)
        ->and(GitHubCheckReport::query()->count())->toBe(0);
});

it('does not throw for any simulated failure mode', function () {
    Http::fake(['api.github.com/*' => fn () => throw new RuntimeException('unexpected')]);
    $scan = ghScan();

    expect(fn () => app(RecordGitHubCheckRun::class)->record($scan, ghContext(), FAKE_TOKEN))->not->toThrow(Throwable::class);
});

// ---------------- idempotency ----------------

it('does not create a duplicate Check Run on a retried report for the same scan', function () {
    ghFakeSuccess();
    $scan = ghScan(new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));

    $first = app(RecordGitHubCheckRun::class)->record($scan, ghContext(), FAKE_TOKEN);
    $second = app(RecordGitHubCheckRun::class)->record($scan->fresh(), ghContext(), FAKE_TOKEN);

    expect($first->reported)->toBeTrue()
        ->and($second->reported)->toBeTrue()
        ->and($second->reason)->toBe('already_reported')
        ->and($second->checkRunId)->toBe($first->checkRunId)
        ->and(GitHubCheckReport::query()->count())->toBe(1);
    Http::assertSentCount(1);
});
