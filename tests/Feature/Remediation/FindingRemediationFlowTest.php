<?php

use App\Audit\Engine\Contracts\AnalyzerCategory;
use App\Audit\Engine\Process\ProcessCommand;
use App\Audit\Engine\Process\ProcessResult;
use App\Audit\Engine\Process\ProcessRunner;
use App\Audit\Findings\Confidence;
use App\Audit\Findings\FindingCandidate;
use App\Audit\Findings\FindingStatus;
use App\Audit\Findings\Severity;
use App\Audit\Projects\RunProjectAudit;
use App\Audit\QualityGates\Policy\MaxOpenFindingsRule;
use App\Audit\QualityGates\Policy\QualityGatePolicy;
use App\Audit\Remediation\FindingRemediationService;
use App\Models\Audit\Finding;
use App\Models\Audit\Scan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Engine\Analyzers\AlwaysPassAnalyzer;
use Tests\Support\Git\GitFixture;
use Tests\Support\Git\GitProject;
use Tests\Support\QualityGates\GateScans;
use Tests\Support\Remediation\RemEvidence;
use Tests\TestCase;

/*
 * The remediation plan built from REAL persisted findings, real scans and real
 * Git repositories (Phase 9 provenance) — the DB-backed half of the planner
 * tests. Fake data only.
 */

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    if (! GitFixture::available()) {
        $this->markTestSkipped('The git binary is not installed.');
    }
});

afterEach(fn () => GitFixture::cleanupAll());

/** @return array{0: GitProject, 1: Finding} */
function remAudited(bool $git = true, ?array $candidates = null): array
{
    $p = GitProject::create($git);
    $p->analyzer->candidates = $candidates ?? [GateScans::candidate('a', Severity::High, 'semgrep', RemEvidence::SQL_RULE)];
    app(RunProjectAudit::class)->run($p->project);

    return [$p, Finding::query()->orderBy('id')->firstOrFail()];
}

function remPlanOf(Finding $finding): array
{
    return app(FindingRemediationService::class)->forFinding($finding->refresh())->toArray();
}

// ---------------- persisted finding -> plan ----------------

it('builds the plan from the persisted finding: public ids, rule guidance, relative evidence, no host path', function () {
    [$p, $finding] = remAudited();
    $plan = remPlanOf($finding);

    expect($plan['finding_id'])->toBe($finding->public_id)
        ->and($plan['project_id'])->toBe($p->project->public_id)
        ->and($plan['rule_id'])->toBe(RemEvidence::SQL_RULE)
        ->and($plan['guidance_available'])->toBeTrue()
        ->and($plan['guidance']['source'])->toBe('rule_catalog')
        ->and($plan['automation_level'])->toBe('guidance_only')
        ->and($plan['evidence']['location']['path'])->toBe('app/a.php')
        ->and($plan['evidence']['location']['line_start'])->toBe(10)
        ->and($plan['provenance'])->toHaveKeys(['guidance_basis', 'rule_version', 'analyzer_version'])
        ->and(json_encode($plan))->not->toContain($p->repo->path)->not->toContain($p->project->path);
});

it('does not persist, mutate or cache anything: only bounded SELECTs', function () {
    [, $finding] = remAudited();
    $finding->refresh();
    $statements = [];
    DB::listen(function ($query) use (&$statements) {
        $statements[] = strtolower(ltrim($query->sql));
    });

    app(FindingRemediationService::class)->forFinding($finding);

    expect($statements)->not->toBe([])->and(count($statements))->toBeLessThanOrEqual(10)
        ->and(array_filter($statements, fn (string $sql) => ! str_starts_with($sql, 'select')))->toBe([]);
});

it('costs a constant number of queries regardless of how many findings exist', function () {
    [, $finding] = remAudited(candidates: [GateScans::candidate('a', Severity::High, 'semgrep', RemEvidence::SQL_RULE)]);
    $count = function () use ($finding): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(FindingRemediationService::class)->forFinding($finding->refresh(), inspectCurrentSource: false);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $few = $count();

    foreach (range(1, 15) as $i) {
        Finding::query()->create([...$finding->only(['project_id', 'rule_id', 'analyzer_id', 'category', 'severity', 'confidence', 'title', 'status', 'first_seen_scan_id', 'last_seen_scan_id']), 'fingerprint' => hash('sha256', "x{$i}"), 'fingerprint_version' => 'v1', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    }

    expect($count())->toBe($few);
});

it('returns the truthful no-guidance fallback for a finding of an unknown rule', function () {
    [, $finding] = remAudited(candidates: [GateScans::candidate('z', Severity::Low, 'semgrep', 'R1')]);
    $plan = remPlanOf($finding);

    expect($plan['guidance_available'])->toBeFalse()->and($plan['guidance']['source'])->toBe('none')
        ->and($plan['automation_level'])->toBe('guidance_only');
});

// ---------------- lifecycle ----------------

it('keeps guidance available and lifecycle honest for every status, without changing the finding', function (FindingStatus $status) {
    [, $finding] = remAudited();
    $finding->forceFill(['status' => $status])->save();
    $updatedAt = $finding->refresh()->updated_at;
    $historyBefore = $finding->statusHistory()->count();

    $plan = remPlanOf($finding);

    expect($plan['lifecycle']['status'])->toBe($status->value)->and($plan['guidance_available'])->toBeTrue()
        ->and($finding->refresh()->status)->toBe($status)->and($finding->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and($finding->statusHistory()->count())->toBe($historyBefore);
})->with(FindingStatus::cases());

// ---------------- source provenance (real Git) ----------------

it('reports the same revision when nothing changed since the finding was observed', function () {
    [, $finding] = remAudited();
    $plan = remPlanOf($finding);

    expect($plan['source']['state'])->toBe('same_revision')
        ->and(array_column($plan['warnings'], 'code'))->toBe([])
        ->and($plan['source']['observed']['revision'])->toBe($plan['source']['current']['revision']);
});

it('warns when the current commit differs from the one where the finding was observed', function () {
    [$p, $finding] = remAudited();
    $p->repo->write('later.txt', 'x');
    $p->repo->commitAll('later');
    $plan = remPlanOf($finding);

    expect($plan['source']['state'])->toBe('changed_since_finding')
        ->and(array_column($plan['warnings'], 'code'))->toContain('source_changed')
        ->and($plan['guidance_available'])->toBeTrue()
        ->and($plan['source']['observed']['revision'])->not->toBe($plan['source']['current']['revision']);
});

it('warns when the working tree is dirty', function () {
    [$p, $finding] = remAudited();
    $p->repo->write('untracked.txt', 'work in progress');

    expect(remPlanOf($finding)['source']['state'])->toBe('dirty');
});

it('reports the source as unavailable when it can no longer be inspected', function () {
    [$p, $finding] = remAudited();
    $moved = $p->repo->path.'-moved';
    rename($p->repo->path, $moved);

    try {
        $plan = remPlanOf($finding);
    } finally {
        rename($moved, $p->repo->path);
    }

    expect($plan['source']['state'])->toBe('unavailable')->and(array_column($plan['warnings'], 'code'))->toContain('source_unavailable')
        ->and($plan['guidance_available'])->toBeTrue();
});

it('says a non-Git project cannot be compared', function () {
    [, $finding] = remAudited(git: false);
    $plan = remPlanOf($finding);

    expect($plan['source']['state'])->toBe('not_versioned')->and(array_column($plan['warnings'], 'code'))->toContain('source_not_versioned');
});

it('handles a legacy scan without any source metadata safely', function () {
    [, $finding] = remAudited();
    Scan::query()->whereKey($finding->last_seen_scan_id)->update(['source_type' => null, 'source_revision' => null, 'source_branch' => null, 'source_dirty' => null]);
    $plan = remPlanOf($finding);

    expect($plan['source']['state'])->toBe('unknown')->and($plan['source']['observed'])->toBeNull()
        ->and(array_column($plan['warnings'], 'code'))->toContain('source_unknown');
});

it('does not inspect Git at all when asked not to (no subprocess)', function () {
    [$p, $finding] = remAudited();
    $plan = app(FindingRemediationService::class)->forFinding($finding->refresh(), inspectCurrentSource: false)->toArray();

    expect($plan['source']['current'])->toBeNull()->and($plan['source']['state'])->toBe('unavailable');
});

// ---------------- quality gate impact from the PERSISTED result ----------------

it('marks a finding listed by a Failed gate rule as blocking, and others as non-blocking', function () {
    $p = GitProject::create();
    GateScans::enable($p->project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));
    $p->analyzer->candidates = [
        GateScans::candidate('bad', Severity::High, 'semgrep', RemEvidence::SQL_RULE),
        GateScans::candidate('meh', Severity::Low, 'semgrep', RemEvidence::SQL_RULE),
    ];
    app(RunProjectAudit::class)->run($p->project);

    $high = Finding::query()->where('severity', 'high')->firstOrFail();
    $low = Finding::query()->where('severity', 'low')->firstOrFail();

    expect(remPlanOf($high)['quality_gate'])->toMatchArray(['impact' => 'blocking'])
        ->and(remPlanOf($low)['quality_gate']['impact'])->toBe('non_blocking')
        ->and(remPlanOf($high)['quality_gate']['gate_scan_id'])->toBe(Scan::query()->latest('id')->firstOrFail()->public_id)
        ->and(array_column(remPlanOf($high)['validation'], 'command'))->toContain('laradogs:project:gate');
});

it('reports not_evaluated when the project has no Quality Gate result', function () {
    [, $finding] = remAudited();

    expect(remPlanOf($finding)['quality_gate']['impact'])->toBe('not_evaluated');
});

it('never re-evaluates history: relaxing the policy afterwards leaves the persisted blocking result untouched', function () {
    $p = GitProject::create();
    GateScans::enable($p->project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));
    $p->analyzer->candidates = [GateScans::candidate('bad', Severity::High, 'semgrep', RemEvidence::SQL_RULE)];
    app(RunProjectAudit::class)->run($p->project);
    $finding = Finding::query()->firstOrFail();

    GateScans::enable($p->project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 100])]));

    expect(remPlanOf($finding)['quality_gate']['impact'])->toBe('blocking');
});

// ---------------- dependency advisories through real persistence ----------------

/** @param array<string,mixed> $metadata */
function remDependencyFinding(string $analyzerId, array $metadata, string $ruleId): Finding
{
    $project = GateScans::project();
    $registry = GateScans::analyzers([$analyzerId => new AlwaysPassAnalyzer($analyzerId)]);
    $candidate = new FindingCandidate(
        ruleId: $ruleId,
        analyzerId: $analyzerId,
        category: AnalyzerCategory::Dependency,
        severity: Severity::High,
        confidence: Confidence::High,
        title: 'Advisory',
        description: 'Affects the package.',
        references: ['https://github.com/advisories/GHSA-zzzz', 'javascript:alert(1)'],
        metadata: $metadata,
    );
    GateScans::scan($project, $registry, [$analyzerId => [$candidate]]);

    return Finding::query()->where('analyzer_id', $analyzerId)->firstOrFail();
}

it('surfaces persisted npm fix metadata through a real persisted finding, without network or process execution', function () {
    Http::preventStrayRequests();
    app()->instance(ProcessRunner::class, new class implements ProcessRunner
    {
        public function run(ProcessCommand $command): ProcessResult
        {
            throw new RuntimeException('remediation must not execute a process');
        }
    });
    $finding = remDependencyFinding('npm-audit', [
        'package_name' => 'lodash', 'source' => 1234567, 'is_direct' => false, 'range' => '<4.17.21',
        'fix_available' => ['name' => 'lodash', 'version' => '4.17.21', 'is_semver_major' => true],
    ], 'lodash:1234567');

    $plan = app(FindingRemediationService::class)->forFinding($finding, inspectCurrentSource: false)->toArray();

    expect($plan['dependency'])->toMatchArray(['ecosystem' => 'npm', 'package' => 'lodash', 'affected_versions' => '<4.17.21', 'fixed_version' => '4.17.21', 'fix_is_semver_major' => true, 'direct_dependency' => false])
        ->and(array_column($plan['warnings'], 'code'))->toContain('dependency_fix_major')
        ->and(array_column($plan['references'], 'url'))->toBe(['https://github.com/advisories/GHSA-zzzz']);
});

it('is honest for a composer advisory: affected range known, fixed version not persisted, never invented', function () {
    $finding = remDependencyFinding('composer-audit', [
        'package_name' => 'vendor/pkg', 'advisory_id' => 'PKSA-abcd-1234', 'affected_versions' => '>=1.0,<1.4.2', 'sources' => [], 'reported_at' => null,
    ], 'vendor/pkg:PKSA-abcd-1234');

    $plan = app(FindingRemediationService::class)->forFinding($finding, inspectCurrentSource: false)->toArray();

    expect($plan['dependency'])->toMatchArray(['ecosystem' => 'composer', 'package' => 'vendor/pkg', 'affected_versions' => '>=1.0,<1.4.2', 'fixed_version' => null, 'fix_available' => null])
        ->and(implode(' ', $plan['guidance']['limitations']))->toContain('does not include a fixed version');
});
