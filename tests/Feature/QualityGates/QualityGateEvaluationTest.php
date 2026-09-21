<?php

use App\Audit\Engine\Execution\AnalyzerCoverage;
use App\Audit\Findings\ActorType;
use App\Audit\Findings\FindingStatus;
use App\Audit\Findings\Ingestion\ScanRecorder;
use App\Audit\Findings\Lifecycle\FindingLifecycleService;
use App\Audit\Findings\ScanOrigin;
use App\Audit\Findings\ScanStatus;
use App\Audit\Findings\Severity;
use App\Audit\Projects\RunProjectAudit;
use App\Audit\QualityGates\EvaluateScanQualityGate;
use App\Audit\QualityGates\Evaluation\GateEvidenceLoader;
use App\Audit\QualityGates\GateRuleId;
use App\Audit\QualityGates\Policy\AnalyzerCoverageRule;
use App\Audit\QualityGates\Policy\AnalyzerStatusRule;
use App\Audit\QualityGates\Policy\CoverageRequirement;
use App\Audit\QualityGates\Policy\MaxOpenFindingsRule;
use App\Audit\QualityGates\Policy\NoNewSeverityRule;
use App\Audit\QualityGates\Policy\QualityGatePolicy;
use App\Audit\QualityGates\QualityGateOutcome as O;
use App\Audit\QualityGates\QualityGatePolicyService;
use App\Models\Audit\Finding;
use App\Models\Audit\ProjectQualityGate;
use App\Models\Audit\QualityGateResult;
use App\Models\Audit\QualityGateRuleResult;
use App\Models\Audit\Scan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Engine\Analyzers\AlwaysPassAnalyzer;
use Tests\Support\Engine\Analyzers\ThrowingAnalyzer;
use Tests\Support\Engine\Analyzers\TimedOutAnalyzer;
use Tests\Support\QualityGates\GateScans;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function qgResult(Scan $scan): ?QualityGateResult
{
    return QualityGateResult::query()->where('scan_id', $scan->id)->first();
}

function qgPolicyMaxHigh(int $max = 0): QualityGatePolicy
{
    return new QualityGatePolicy([new MaxOpenFindingsRule(['high' => $max])]);
}

// ---------------- default / disabled ----------------

it('has no gate by default: a completed scan of a project without a policy produces no result (never a fake Passed)', function () {
    $project = GateScans::project();

    $scan = GateScans::scan($project, candidatesByAnalyzer: ['semgrep' => [GateScans::candidate('a')]]);

    expect(ProjectQualityGate::query()->count())->toBe(0)
        ->and(qgResult($scan))->toBeNull()
        ->and(QualityGateResult::query()->count())->toBe(0);
});

it('produces no result while the gate is disabled, even with a saved policy', function () {
    $project = GateScans::project();
    app(QualityGatePolicyService::class)->update($project, false, qgPolicyMaxHigh());

    $scan = GateScans::scan($project, candidatesByAnalyzer: ['semgrep' => [GateScans::candidate('a')]]);

    expect(ProjectQualityGate::query()->firstOrFail()->enabled)->toBeFalse()
        ->and(qgResult($scan))->toBeNull();
});

// ---------------- evaluation happens on scan completion ----------------

it('evaluates an enabled gate when the scan completes and persists the result with revision, snapshot and rule rows', function () {
    $project = GateScans::project();
    $policy = new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0, 'critical' => 0]), new AnalyzerStatusRule(['semgrep'])]);
    $gate = GateScans::enable($project, $policy);

    $scan = GateScans::scan($project, candidatesByAnalyzer: ['semgrep' => [GateScans::candidate('a')]]);
    $result = qgResult($scan);

    expect($result)->not->toBeNull()
        ->and($result->outcome)->toBe(O::Failed)
        ->and($result->policy_revision)->toBe($gate->revision)
        ->and(QualityGatePolicy::fromArray($result->policy_snapshot)->toArray())->toBe($policy->toArray())
        ->and($result->rules_total)->toBe(3)
        ->and($result->rules_failed)->toBe(1)
        ->and($result->ruleResults)->toHaveCount(3);

    $high = $result->ruleResults->first(fn (QualityGateRuleResult $r) => $r->subject === 'high');
    expect($high->rule_id)->toBe(GateRuleId::MaxOpenFindings)
        ->and($high->outcome)->toBe(O::Failed)
        ->and($high->observed)->toBe('1')
        ->and($high->expected)->toBe('<= 0')
        ->and($high->finding_count)->toBe(1)
        ->and($high->finding_ids)->toHaveCount(1);
});

it('passes a clean, fully-verified audit', function () {
    $project = GateScans::project();
    GateScans::enable($project, new QualityGatePolicy([
        new MaxOpenFindingsRule(['critical' => 0, 'high' => 0]),
        new AnalyzerStatusRule(['semgrep', 'composer-audit']),
        new AnalyzerCoverageRule(['semgrep' => CoverageRequirement::ExplicitOrFull]),
    ]));

    $result = qgResult(GateScans::scan($project));

    expect($result->outcome)->toBe(O::Passed)
        ->and($result->rules_failed)->toBe(0)
        ->and($result->rules_indeterminate)->toBe(0);
});

it('is idempotent: evaluating the same scan again keeps the first result', function () {
    $project = GateScans::project();
    GateScans::enable($project, qgPolicyMaxHigh());
    $scan = GateScans::scan($project);
    $first = qgResult($scan);

    $again = app(EvaluateScanQualityGate::class)($scan);

    expect($again->id)->toBe($first->id)
        ->and(QualityGateResult::query()->count())->toBe(1)
        ->and(QualityGateRuleResult::query()->count())->toBe($first->ruleResults()->count());
});

it('never gates a Queued or Running scan', function () {
    $project = GateScans::project();
    GateScans::enable($project, qgPolicyMaxHigh());

    $queued = app(RunProjectAudit::class)->enqueue($project, ScanOrigin::Manual)->scan;

    expect(app(EvaluateScanQualityGate::class)($queued))->toBeNull();

    $queued->status = ScanStatus::Running;
    expect(app(EvaluateScanQualityGate::class)($queued))->toBeNull()
        ->and(QualityGateResult::query()->count())->toBe(0);
});

// ---------------- finding status semantics (central rule) ----------------

it('counts only Open and Confirmed findings — Resolved, False Positive, Ignored and Accepted Risk do not count', function () {
    $project = GateScans::project();
    $candidates = array_map(fn (string $k) => GateScans::candidate($k), ['open', 'confirmed', 'resolved', 'fp', 'ignored', 'accepted']);
    $scan = GateScans::scan($project, candidatesByAnalyzer: ['semgrep' => $candidates]);

    $lifecycle = app(FindingLifecycleService::class);
    $set = function (string $key, FindingStatus $status) use ($project, $lifecycle) {
        $finding = Finding::query()->where('project_id', $project->id)->where('title', "Finding {$key}")->firstOrFail();
        $lifecycle->transition($finding, $status, ActorType::User, actorIdentifier: 'tester', reason: 'test reason');
    };
    $set('confirmed', FindingStatus::Confirmed);
    $set('resolved', FindingStatus::Resolved);
    $set('fp', FindingStatus::FalsePositive);
    $set('ignored', FindingStatus::Ignored);
    $set('accepted', FindingStatus::AcceptedRisk);

    $evidence = app(GateEvidenceLoader::class)->load($scan, qgPolicyMaxHigh());

    expect($evidence->openCountsBySeverity)->toBe(['high' => 2]);
});

it('does not touch the underlying findings or their lifecycle when evaluating', function () {
    $project = GateScans::project();
    $scan = GateScans::scan($project, candidatesByAnalyzer: ['semgrep' => [GateScans::candidate('a'), GateScans::candidate('b')]]);
    app(FindingLifecycleService::class)->transition(
        Finding::query()->where('title', 'Finding b')->firstOrFail(), FindingStatus::AcceptedRisk, ActorType::User, actorIdentifier: 't', reason: 'accepted',
    );
    $before = Finding::query()->orderBy('id')->get(['id', 'status', 'updated_at', 'last_seen_scan_id'])->toArray();
    GateScans::enable($project, qgPolicyMaxHigh());

    app(EvaluateScanQualityGate::class)($scan);

    expect(Finding::query()->orderBy('id')->get(['id', 'status', 'updated_at', 'last_seen_scan_id'])->toArray())->toBe($before);
});

// ---------------- baseline / new findings (real fingerprint identity) ----------------

it('is Indeterminate for the first scan: there is no baseline to compare against', function () {
    $project = GateScans::project();
    GateScans::enable($project, new QualityGatePolicy([new NoNewSeverityRule(Severity::High)]));

    $result = qgResult(GateScans::scan($project, candidatesByAnalyzer: ['semgrep' => [GateScans::candidate('a')]]));

    expect($result->outcome)->toBe(O::Indeterminate)
        ->and($result->baseline_scan_id)->toBeNull();
});

it('fails on a genuinely new High finding and does not count the same logical finding as new', function () {
    $project = GateScans::project();
    $baseline = GateScans::scan($project, candidatesByAnalyzer: ['semgrep' => [GateScans::candidate('old')]]);
    GateScans::enable($project, new QualityGatePolicy([new NoNewSeverityRule(Severity::High)]));

    $current = GateScans::scan($project, candidatesByAnalyzer: ['semgrep' => [GateScans::candidate('old'), GateScans::candidate('fresh')]]);
    $result = qgResult($current);

    expect($result->outcome)->toBe(O::Failed)
        ->and($result->baseline_scan_id)->toBe($baseline->id)
        ->and($result->ruleResults->first()->finding_count)->toBe(1)
        ->and($result->ruleResults->first()->finding_ids)->toBe([Finding::query()->where('title', 'Finding fresh')->value('public_id')]);
});

it('passes when the same finding merely moved lines (identity is the fingerprint, not the line)', function () {
    $project = GateScans::project();
    GateScans::scan($project, candidatesByAnalyzer: ['semgrep' => [GateScans::candidate('old', line: 10)]]);
    GateScans::enable($project, new QualityGatePolicy([new NoNewSeverityRule(Severity::High)]));

    $current = GateScans::scan($project, candidatesByAnalyzer: ['semgrep' => [GateScans::candidate('old', line: 90)]]);

    expect(qgResult($current)->outcome)->toBe(O::Passed);
});

it('does not count new findings below the configured minimum severity', function () {
    $project = GateScans::project();
    GateScans::scan($project);
    GateScans::enable($project, new QualityGatePolicy([new NoNewSeverityRule(Severity::High)]));

    $current = GateScans::scan($project, candidatesByAnalyzer: ['semgrep' => [GateScans::candidate('med', Severity::Medium)]]);

    expect(qgResult($current)->outcome)->toBe(O::Passed);
});

it('detects a regression (resolved, then reappearing) when the baseline verified the rule', function () {
    $project = GateScans::project();
    GateScans::scan($project, candidatesByAnalyzer: ['semgrep' => [GateScans::candidate('x')]]);       // A: seen
    GateScans::scan($project);                                                                        // B: semgrep verifies R1, absent -> auto-resolved
    expect(Finding::query()->where('title', 'Finding x')->firstOrFail()->status)->toBe(FindingStatus::Resolved);

    GateScans::enable($project, new QualityGatePolicy([new NoNewSeverityRule(Severity::High)]));
    $current = GateScans::scan($project, candidatesByAnalyzer: ['semgrep' => [GateScans::candidate('x')]]); // C: reappears

    $result = qgResult($current);
    expect($result->outcome)->toBe(O::Failed)
        ->and($result->ruleResults->first()->summary)->toContain('1 regressed')
        // Regression is comparison metadata — never a persisted lifecycle status.
        ->and(Finding::query()->where('title', 'Finding x')->firstOrFail()->status)->toBe(FindingStatus::Open);
});

it('is Indeterminate (not Failed, not Passed) when a reappearance cannot be proven a regression because the baseline did not verify the rule', function () {
    $project = GateScans::project();
    $unverified = fn () => GateScans::analyzers(['semgrep' => new AlwaysPassAnalyzer('semgrep')]); // Passed, coverage Unknown
    GateScans::scan($project, $unverified(), ['semgrep' => [GateScans::candidate('x')]]);
    GateScans::scan($project, $unverified());                                                     // baseline: Unknown coverage, x not observed
    GateScans::enable($project, new QualityGatePolicy([new NoNewSeverityRule(Severity::High)]));

    $current = GateScans::scan($project, $unverified(), ['semgrep' => [GateScans::candidate('x')]]);

    expect(qgResult($current)->outcome)->toBe(O::Indeterminate);
});

it('never uses a Failed, Queued or Running scan as the baseline', function () {
    $project = GateScans::project();
    $good = GateScans::scan($project, candidatesByAnalyzer: ['semgrep' => [GateScans::candidate('old')]]);

    $failed = app(ScanRecorder::class)->failScan(app(RunProjectAudit::class)->enqueue($project, ScanOrigin::Cli)->scan);
    expect($failed->status)->toBe(ScanStatus::Failed);

    GateScans::enable($project, new QualityGatePolicy([new NoNewSeverityRule(Severity::High)]));
    $current = GateScans::scan($project, candidatesByAnalyzer: ['semgrep' => [GateScans::candidate('old')]]);

    expect(qgResult($current)->baseline_scan_id)->toBe($good->id);
});

// ---------------- coverage-aware / analyzer rules through real scans ----------------

it('is Indeterminate — not Passed — when Semgrep timed out and the finding counts look clean', function () {
    $project = GateScans::project();
    GateScans::enable($project, qgPolicyMaxHigh());

    $scan = GateScans::scan($project, GateScans::analyzers(['semgrep' => new TimedOutAnalyzer('semgrep')]));

    expect(qgResult($scan)->outcome)->toBe(O::Indeterminate);
});

it('fails an analyzer-status rule when the required analyzer timed out or failed', function (string $kind) {
    $project = GateScans::project();
    GateScans::enable($project, new QualityGatePolicy([new AnalyzerStatusRule(['semgrep'])]));
    $analyzer = $kind === 'timeout' ? new TimedOutAnalyzer('semgrep') : new ThrowingAnalyzer('semgrep');

    $scan = GateScans::scan($project, GateScans::analyzers(['semgrep' => $analyzer]));

    expect(qgResult($scan)->outcome)->toBe(O::Failed);
})->with(['timeout', 'failure']);

it('fails a coverage requirement when the analyzer only declared Unknown coverage', function () {
    $project = GateScans::project();
    GateScans::enable($project, new QualityGatePolicy([new AnalyzerCoverageRule(['composer-audit' => CoverageRequirement::ExplicitOrFull])]));

    $scan = GateScans::scan($project, GateScans::analyzers(['composer-audit' => new AlwaysPassAnalyzer('composer-audit', AnalyzerCoverage::unknown())]));

    expect(qgResult($scan)->outcome)->toBe(O::Failed);
});

it('is Indeterminate when a required analyzer has no execution in the scan', function () {
    $project = GateScans::project();
    GateScans::enable($project, new QualityGatePolicy([new AnalyzerStatusRule(['npm-audit'])]));

    expect(qgResult(GateScans::scan($project))->outcome)->toBe(O::Indeterminate);
});

// ---------------- failed scans ----------------

it('never yields Passed for a Failed scan: it is evaluated as Indeterminate', function () {
    $project = GateScans::project();
    GateScans::enable($project, new QualityGatePolicy([
        new MaxOpenFindingsRule(['high' => 0]),
        new AnalyzerStatusRule(['semgrep']),
    ]));

    $failed = app(ScanRecorder::class)->failScan(app(RunProjectAudit::class)->enqueue($project, ScanOrigin::Manual)->scan);
    $result = qgResult($failed);

    expect($result)->not->toBeNull()
        ->and($result->outcome)->toBe(O::Indeterminate)
        ->and($result->outcome)->not->toBe(O::Passed);
});

// ---------------- policy revision / historical immutability ----------------

it('keeps a historical result truthful after the policy changes: same revision, same snapshot, same outcome', function () {
    $project = GateScans::project();
    $service = app(QualityGatePolicyService::class);

    $rev1 = $service->update($project, true, qgPolicyMaxHigh(0));
    $scanA = GateScans::scan($project, candidatesByAnalyzer: ['semgrep' => [GateScans::candidate('a')]]);
    $resultA = qgResult($scanA);
    $snapshotA = QualityGatePolicy::fromArray($resultA->policy_snapshot)->toArray();
    expect($resultA->policy_revision)->toBe(1)->and($resultA->outcome)->toBe(O::Failed);

    $rev2 = $service->update($project, true, qgPolicyMaxHigh(5)); // now lenient
    expect($rev2->revision)->toBe(2);

    // Scan A's stored result is untouched by the edit...
    $resultA->refresh();
    expect($resultA->policy_revision)->toBe(1)
        ->and(QualityGatePolicy::fromArray($resultA->policy_snapshot)->toArray())->toBe($snapshotA)
        ->and($resultA->outcome)->toBe(O::Failed);

    // ...and only future scans use revision 2.
    $scanB = GateScans::scan($project, candidatesByAnalyzer: ['semgrep' => [GateScans::candidate('a')]]);
    $resultB = qgResult($scanB);
    expect($resultB->policy_revision)->toBe(2)
        ->and($resultB->outcome)->toBe(O::Passed)
        ->and($resultB->policy_snapshot['rules'][0]['limits']['high'])->toBe(5);
});

it('keeps historical results when the gate is disabled and stops evaluating new scans', function () {
    $project = GateScans::project();
    $service = app(QualityGatePolicyService::class);
    $service->update($project, true, qgPolicyMaxHigh());
    $scanA = GateScans::scan($project);

    $service->update($project, false, null);
    $scanB = GateScans::scan($project);

    expect(qgResult($scanA))->not->toBeNull()
        ->and(qgResult($scanB))->toBeNull()
        ->and(QualityGateResult::query()->count())->toBe(1);
});

it('never retroactively evaluates scans that pre-date the gate', function () {
    $project = GateScans::project();
    $legacy = GateScans::scan($project, candidatesByAnalyzer: ['semgrep' => [GateScans::candidate('a')]]);

    GateScans::enable($project, qgPolicyMaxHigh());

    expect(qgResult($legacy))->toBeNull()
        ->and(QualityGateResult::query()->count())->toBe(0);
});

// ---------------- isolation ----------------

it('never breaks or rolls back a finished scan when gate evaluation itself fails', function () {
    $project = GateScans::project();
    GateScans::enable($project, qgPolicyMaxHigh());
    // A stored document that the strict parser rejects → evaluation throws.
    ProjectQualityGate::query()->update(['policy' => ['schema' => 99, 'rules' => []]]);

    $scan = GateScans::scan($project, candidatesByAnalyzer: ['semgrep' => [GateScans::candidate('a')]]);

    expect($scan->fresh()->status)->toBe(ScanStatus::Completed)
        ->and(Finding::query()->count())->toBe(1)
        ->and(qgResult($scan))->toBeNull();
});
