<?php

use App\Audit\Engine\Process\SymfonyProcessRunner;
use App\Audit\Findings\FindingStatus;
use App\Audit\Findings\ScanStatus;
use App\Audit\Findings\Severity;
use App\Audit\Projects\RegisterProject;
use App\Audit\Projects\RunProjectAudit;
use App\Audit\QualityGates\Policy\MaxOpenFindingsRule;
use App\Audit\QualityGates\Policy\QualityGatePolicy;
use App\Audit\QualityGates\QualityGateOutcome;
use App\Audit\Source\Git\GitRepositoryInspector;
use App\Models\Audit\Finding;
use App\Models\Audit\Scan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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

function siAudit(GitProject $p): Scan
{
    $result = app(RunProjectAudit::class)->run($p->project);
    expect($result->scan?->status)->toBe(ScanStatus::Completed);

    return $result->scan->refresh();
}

/** Makes the container's inspector unable to run Git at all. */
function siGitUnavailable(): void
{
    app()->instance(GitRepositoryInspector::class, new GitRepositoryInspector(new SymfonyProcessRunner(65_536), binary: '/nonexistent/git-binary'));
}

function siGate(GitProject $p): void
{
    GateScans::enable($p->project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));
}

/** An unverified-source scenario: [description => [setup callable, expected reason]]. */
dataset('unverifiable sources', [
    'dirty at start' => [fn (GitProject $p) => $p->repo->write('wip.txt', 'uncommitted'), 'dirty_at_start'],
    'unavailable Git' => [fn (GitProject $p) => siGitUnavailable(), 'unavailable'],
    'unsafe config (include)' => [fn (GitProject $p) => $p->repo->config('include.path', '/etc/hostname'), 'unsafe_config'],
    'changed during the audit' => [fn (GitProject $p) => $p->analyzer->whileRunning = fn () => $p->repo->write('mid.txt', 'x'), 'changed_during_audit'],
]);

// ---------------- Finding reconciliation ----------------

it('reconciles normally for a clean, stable Git repository', function () {
    $p = GitProject::create();
    $p->analyzer->candidates = [GateScans::candidate('old')];
    siAudit($p);
    $p->analyzer->candidates = [];

    $scan = siAudit($p);

    expect($scan->source_consistent)->toBeTrue()
        ->and($scan->source_integrity_reason)->toBeNull()
        ->and(Finding::query()->firstOrFail()->status)->toBe(FindingStatus::Resolved);
});

it('never auto-resolves an absent finding when source integrity is not established', function (Closure $setup, string $reason) {
    $p = GitProject::create();
    $p->analyzer->candidates = [GateScans::candidate('old')];
    siAudit($p);
    $old = Finding::query()->firstOrFail();

    $p->analyzer->candidates = [GateScans::candidate('fresh', Severity::Medium, ruleId: 'R2')];
    $setup($p);
    $scan = siAudit($p);

    expect($scan->source_consistent)->toBeFalse()
        ->and($scan->source_integrity_reason)->toBe($reason)
        ->and($old->refresh()->status)->toBe(FindingStatus::Open)
        ->and($scan->findings_summary['auto_resolved'])->toBe(0)
        // Positive findings from an unverified source are still ingested.
        ->and(Finding::query()->count())->toBe(2);
})->with('unverifiable sources');

it('keeps the intentional non-Git behavior: a genuine non-Git target still reconciles', function () {
    $p = GitProject::create(git: false);
    $p->analyzer->candidates = [GateScans::candidate('old')];
    siAudit($p);
    $p->analyzer->candidates = [];

    $scan = siAudit($p);

    expect($scan->source_type)->toBe('none')
        ->and($scan->source_consistent)->toBeNull()
        ->and($scan->source_integrity_reason)->toBeNull()
        ->and(Finding::query()->firstOrFail()->status)->toBe(FindingStatus::Resolved);
});

it('does not attest a Git repository without commits', function () {
    $p = GitProject::create(commit: false);
    $p->analyzer->candidates = [GateScans::candidate('old')];
    siAudit($p);
    $p->analyzer->candidates = [];

    $scan = siAudit($p);

    expect($scan->source_type)->toBe('git')
        ->and($scan->source_revision)->toBeNull()
        ->and($scan->source_consistent)->toBeFalse()
        ->and($scan->source_integrity_reason)->toBe('no_commits')
        ->and(Finding::query()->firstOrFail()->status)->toBe(FindingStatus::Open);
});

it('does not attest a bare repository as an audited working tree', function () {
    $bare = GitFixture::bare();
    GitProject::create(git: false); // binds the scripted analyzer registry
    $project = (new RegisterProject)->register($bare->path)->project;
    GateScans::enable($project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));

    $scan = app(RunProjectAudit::class)->run($project)->scan->refresh();

    expect($scan->source_type)->toBe('bare')
        ->and($scan->source_consistent)->toBeFalse()
        ->and($scan->source_integrity_reason)->toBe('bare_repository')
        ->and($scan->qualityGateResult->outcome)->toBe(QualityGateOutcome::Indeterminate);
});

// ---------------- Quality Gate ----------------

it('makes an absence-based rule Indeterminate whenever source integrity is not established', function (Closure $setup, string $reason) {
    $p = GitProject::create();
    siGate($p);
    $setup($p);

    $result = siAudit($p)->qualityGateResult;

    expect($result->outcome)->toBe(QualityGateOutcome::Indeterminate);
})->with('unverifiable sources');

it('still fails a proven violation whenever source integrity is not established', function (Closure $setup, string $reason) {
    $p = GitProject::create();
    siGate($p);
    $p->analyzer->candidates = [GateScans::candidate('bad', Severity::High)];
    $setup($p);

    expect(siAudit($p)->qualityGateResult->outcome)->toBe(QualityGateOutcome::Failed);
})->with('unverifiable sources');

it('states the true reason in the gate summary — "changed" only when it really changed', function () {
    $dirty = GitProject::create();
    siGate($dirty);
    $dirty->repo->write('wip.txt', 'x');
    $summary = siAudit($dirty)->qualityGateResult->ruleResults->first()->summary;

    expect($summary)->toContain('working tree was dirty when the audit began')->not->toContain('source changed');

    $changed = GitProject::create();
    siGate($changed);
    $changed->analyzer->whileRunning = fn () => $changed->repo->write('mid.txt', 'x');

    expect(siAudit($changed)->qualityGateResult->ruleResults->first()->summary)->toContain('source changed while the audit was running');

    $unavailable = GitProject::create();
    siGate($unavailable);
    siGitUnavailable();

    expect(siAudit($unavailable)->qualityGateResult->ruleResults->first()->summary)->toContain('Git source integrity could not be verified');
});

it('preserves the existing Passed behavior and the Phase 8 exit-code contract for a clean stable Git repository', function () {
    $p = GitProject::create();
    siGate($p);
    $scan = siAudit($p);

    expect($scan->qualityGateResult->outcome)->toBe(QualityGateOutcome::Passed)
        ->and(Artisan::call('laradogs:project:gate', ['project' => $p->project->public_id]))->toBe(0);

    $q = GitProject::create();
    siGate($q);
    $q->repo->write('wip.txt', 'x');
    siAudit($q);
    expect(Artisan::call('laradogs:project:gate', ['project' => $q->project->public_id]))->toBe(2);

    $r = GitProject::create();
    siGate($r);
    $r->repo->write('wip.txt', 'x');
    $r->analyzer->candidates = [GateScans::candidate('bad', Severity::High)];
    siAudit($r);
    expect(Artisan::call('laradogs:project:gate', ['project' => $r->project->public_id]))->toBe(1);
});

it('keeps a genuine non-Git project eligible for a normal Passed gate', function () {
    $p = GitProject::create(git: false);
    siGate($p);

    expect(siAudit($p)->qualityGateResult->outcome)->toBe(QualityGateOutcome::Passed);
});

// ---------------- wording (UI / CLI) ----------------

it('never claims the source "changed" when it only could not be proven', function () {
    $p = GitProject::create();
    $p->repo->write('wip.txt', 'x');
    Artisan::call('laradogs:project:audit', ['project' => $p->project->public_id]);
    $out = Artisan::output();

    expect($out)->toContain('Source integrity could not be proven because the working tree was dirty when the audit began.')
        ->not->toContain('changed while the audit was running');

    $q = GitProject::create();
    siGitUnavailable();
    Artisan::call('laradogs:project:audit', ['project' => $q->project->public_id]);

    expect(Artisan::output())->toContain('Git source integrity could not be verified.')->not->toContain('changed');
});

it('exposes the truthful integrity fields in the dashboard payload and the JSON block', function () {
    $p = GitProject::create();
    $p->repo->write('wip.txt', 'x');
    $scan = siAudit($p);

    $user = User::factory()->create();
    $this->actingAs($user)->get(route('projects.scans.show', [$p->project, $scan]))
        ->assertInertia(fn ($page) => $page
            ->where('scan.source.consistent', false)
            ->where('scan.source.integrity_reason', 'dirty_at_start')
            ->where('scan.source.integrity_changed', false)
            ->where('scan.source.integrity_message', 'Source integrity could not be proven because the working tree was dirty when the audit began.'));

    Artisan::call('laradogs:project:audit', ['project' => $p->project->public_id, '--json' => true]);
    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($json['scan']['source']['consistent'])->toBeFalse()
        ->and($json['scan']['source']['integrity_reason'])->toBe('dirty_at_start');
});
