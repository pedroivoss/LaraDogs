<?php

use App\Audit\Findings\FindingStatus;
use App\Audit\Findings\ScanStatus;
use App\Audit\Findings\Severity;
use App\Audit\Projects\RunProjectAudit;
use App\Audit\QualityGates\Policy\AnalyzerStatusRule;
use App\Audit\QualityGates\Policy\MaxOpenFindingsRule;
use App\Audit\QualityGates\Policy\QualityGatePolicy;
use App\Audit\QualityGates\QualityGateOutcome;
use App\Audit\Source\Git\GitSnapshot;
use App\Http\Support\SourcePayload;
use App\Models\Audit\Finding;
use App\Models\Audit\Scan;
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

function srcAudit(GitProject $p): Scan
{
    $result = app(RunProjectAudit::class)->run($p->project);
    expect($result->scan?->status)->toBe(ScanStatus::Completed);

    return $result->scan->refresh();
}

// ---------------- snapshot persistence ----------------

it('persists an immutable Git snapshot with the scan, captured before the analyzers', function () {
    $p = GitProject::create();
    $p->repo->git('remote', 'add', 'origin', 'https://user:tok@example.com/org/app.git');
    $seenByAnalyzer = null;
    $p->analyzer->whileRunning = function () use (&$seenByAnalyzer, $p) {
        $seenByAnalyzer = Scan::query()->where('project_id', $p->project->id)->latest('id')->first()?->source_revision;
    };

    $scan = srcAudit($p);

    expect($scan->source_type)->toBe('git')
        ->and($scan->source_revision)->toBe($p->repo->sha())
        ->and($scan->source_revision)->toHaveLength(40)
        ->and($scan->source_branch)->toBe('main')
        ->and($scan->source_detached)->toBeFalse()
        ->and($scan->source_dirty)->toBeFalse()
        ->and($scan->source_consistent)->toBeTrue()
        ->and($scan->source_commit_subject)->toBe('Fixture project')
        ->and($scan->source_commit_at)->not->toBeNull()
        ->and($scan->source_remote)->toBe('https://example.com/org/app.git')
        // Already persisted while the analyzers were still running.
        ->and($seenByAnalyzer)->toBe($p->repo->sha());
});

it('audits a non-Git project normally with no source identity', function () {
    $p = GitProject::create(git: false);

    $scan = srcAudit($p);

    expect($scan->source_type)->toBe('none')
        ->and($scan->source_revision)->toBeNull()
        ->and($scan->source_consistent)->toBeNull()
        ->and($scan->status)->toBe(ScanStatus::Completed);
});

it('leaves a legacy scan with null source metadata and never fabricates history', function () {
    $project = GateScans::project();
    $legacy = GateScans::scan($project); // pre-Phase-9 path: no snapshot captured

    expect($legacy->source_type)->toBeNull()
        ->and($legacy->source_revision)->toBeNull()
        ->and($legacy->source_consistent)->toBeNull()
        ->and(GitSnapshot::fromScan($legacy))->toBeNull()
        ->and(SourcePayload::forScan($legacy))->toBeNull();
});

it('records a dirty audit as dirty and not reproducible from the SHA alone', function () {
    $p = GitProject::create();
    $p->repo->write('composer.json.bak', 'local edit');

    $scan = srcAudit($p);
    $snapshot = GitSnapshot::fromScan($scan);

    expect($scan->source_dirty)->toBeTrue()
        ->and($snapshot?->isReproducible())->toBeFalse()
        ->and(SourcePayload::forScan($scan)['reproducible'])->toBeFalse();
});

it('keeps the historical snapshot while the current source moves on', function () {
    $p = GitProject::create();
    $scan = srcAudit($p);
    $auditedSha = $p->repo->sha();

    $p->repo->write('later.txt', 'new work');
    $newSha = $p->repo->commitAll('Later commit');

    $scan->refresh();

    expect($scan->source_revision)->toBe($auditedSha)
        ->and($scan->source_revision)->not->toBe($newSha);
});

// ---------------- source consistency ----------------

it('marks a scan consistent when the source does not change while it runs', function () {
    $p = GitProject::create();

    expect(srcAudit($p)->source_consistent)->toBeTrue();
});

it('marks a scan inconsistent when the commit changes during the audit', function () {
    $p = GitProject::create();
    $p->analyzer->whileRunning = function () use ($p) {
        $p->repo->write('mid.txt', 'x');
        $p->repo->commitAll('Commit made while auditing');
    };

    $scan = srcAudit($p);

    expect($scan->source_consistent)->toBeFalse()
        // The snapshot is still the state BEFORE the analyzers.
        ->and($scan->source_dirty)->toBeFalse();
});

it('marks a scan inconsistent when the working tree becomes dirty during the audit', function () {
    $p = GitProject::create();
    $p->analyzer->whileRunning = fn () => $p->repo->write('composer.json.bak', 'edited mid-audit');

    expect(srcAudit($p)->source_consistent)->toBeFalse();
});

it('marks a scan inconsistent when the branch or detached identity changes during the audit', function () {
    $p = GitProject::create();
    $p->analyzer->whileRunning = fn () => $p->repo->git('checkout', '-q', '-b', 'other');

    expect(srcAudit($p)->source_consistent)->toBeFalse();

    $q = GitProject::create();
    $q->analyzer->whileRunning = fn () => $q->repo->detach();

    expect(srcAudit($q)->source_consistent)->toBeFalse();
});

// ---------------- finding lifecycle safety ----------------

it('never auto-resolves an absent finding from a source-inconsistent scan, but still records positives', function () {
    $p = GitProject::create();
    $p->analyzer->candidates = [GateScans::candidate('old')];
    srcAudit($p);

    $old = Finding::query()->where('project_id', $p->project->id)->firstOrFail();
    expect($old->status)->toBe(FindingStatus::Open);

    // Scan 2: 'old' is no longer reported (its rule IS covered), a NEW one is —
    // but the repository changed while the analyzers ran.
    $p->analyzer->candidates = [GateScans::candidate('fresh', Severity::Medium, ruleId: 'R2')];
    $p->analyzer->whileRunning = fn () => $p->repo->write('mid.txt', 'changed during audit');
    $scan = srcAudit($p);

    expect($scan->source_consistent)->toBeFalse()
        ->and($old->refresh()->status)->toBe(FindingStatus::Open)
        ->and($scan->findings_summary['auto_resolved'])->toBe(0)
        ->and(Finding::query()->where('project_id', $p->project->id)->count())->toBe(2);
});

it('still reconciles normally when the source stayed consistent', function () {
    $p = GitProject::create();
    $p->analyzer->candidates = [GateScans::candidate('old')];
    srcAudit($p);
    $old = Finding::query()->where('project_id', $p->project->id)->firstOrFail();

    $p->analyzer->candidates = [];
    $scan = srcAudit($p);

    expect($scan->source_consistent)->toBeTrue()
        ->and($old->refresh()->status)->toBe(FindingStatus::Resolved)
        ->and($scan->findings_summary['auto_resolved'])->toBe(1);
});

// ---------------- quality gate safety ----------------

function srcGate(GitProject $p, ?array $rules = null): void
{
    GateScans::enable($p->project, new QualityGatePolicy($rules ?? [new MaxOpenFindingsRule(['high' => 0])]));
}

it('cannot Pass an absence-based rule on a source-inconsistent scan (Indeterminate)', function () {
    $p = GitProject::create();
    srcGate($p);
    $p->analyzer->whileRunning = fn () => $p->repo->write('mid.txt', 'x');

    $scan = srcAudit($p);
    $result = $scan->qualityGateResult;

    expect($result->outcome)->toBe(QualityGateOutcome::Indeterminate)
        ->and($result->ruleResults->first()->summary)->toContain('source changed while the audit was running');
});

it('still fails a proven violation on a source-inconsistent scan', function () {
    $p = GitProject::create();
    srcGate($p);
    $p->analyzer->candidates = [GateScans::candidate('bad', Severity::High)];
    $p->analyzer->whileRunning = fn () => $p->repo->write('mid.txt', 'x');

    expect(srcAudit($p)->qualityGateResult->outcome)->toBe(QualityGateOutcome::Failed);
});

it('leaves gate behavior unchanged for a source-consistent scan', function () {
    $p = GitProject::create();
    srcGate($p);

    expect(srcAudit($p)->qualityGateResult->outcome)->toBe(QualityGateOutcome::Passed);
});

it('does not penalize analyzer-status rules (they judge the execution itself) when the source changed', function () {
    $p = GitProject::create();
    srcGate($p, [new AnalyzerStatusRule(['semgrep'])]);
    $p->analyzer->whileRunning = fn () => $p->repo->write('mid.txt', 'x');

    expect(srcAudit($p)->qualityGateResult->outcome)->toBe(QualityGateOutcome::Passed);
});

it('keeps the Phase 8 exit-code contract: an inconsistent scan exits 2 (Indeterminate)', function () {
    $p = GitProject::create();
    srcGate($p);
    $p->analyzer->whileRunning = fn () => $p->repo->write('mid.txt', 'x');
    srcAudit($p);

    expect(Artisan::call('laradogs:project:gate', ['project' => $p->project->public_id]))->toBe(2);

    $q = GitProject::create();
    srcGate($q);
    srcAudit($q);
    expect(Artisan::call('laradogs:project:gate', ['project' => $q->project->public_id]))->toBe(0);

    $r = GitProject::create();
    srcGate($r);
    $r->analyzer->candidates = [GateScans::candidate('bad', Severity::High)];
    srcAudit($r);
    expect(Artisan::call('laradogs:project:gate', ['project' => $r->project->public_id]))->toBe(1);
});
