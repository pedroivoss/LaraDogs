<?php

use App\Audit\Engine\Execution\AnalyzerCoverage;
use App\Audit\Engine\Execution\ExecutionStatus;
use App\Audit\Findings\Severity;
use App\Audit\QualityGates\Evaluation\ExecutionEvidence;
use App\Audit\QualityGates\Evaluation\GateEvidence;
use App\Audit\QualityGates\Evaluation\NewFindingCandidate;
use App\Audit\QualityGates\Evaluation\QualityGateEvaluation;
use App\Audit\QualityGates\Evaluation\QualityGateEvaluator;
use App\Audit\QualityGates\GateRuleId;
use App\Audit\QualityGates\Policy\AnalyzerCoverageRule;
use App\Audit\QualityGates\Policy\AnalyzerStatusRule;
use App\Audit\QualityGates\Policy\CoverageRequirement;
use App\Audit\QualityGates\Policy\MaxOpenFindingsRule;
use App\Audit\QualityGates\Policy\NoNewSeverityRule;
use App\Audit\QualityGates\Policy\QualityGatePolicy;
use App\Audit\QualityGates\QualityGateOutcome as O;

function qgExec(ExecutionStatus $status = ExecutionStatus::Passed, ?AnalyzerCoverage $coverage = null): ExecutionEvidence
{
    return new ExecutionEvidence($status, $coverage ?? AnalyzerCoverage::unknown());
}

/**
 * @param  array<string,ExecutionEvidence>|null  $executions
 * @param  array<string,int>  $counts
 * @param  list<NewFindingCandidate>  $candidates
 * @param  array<string,ExecutionEvidence>  $baselineExecutions
 */
function qgEvidence(
    bool $completed = true,
    ?array $executions = null,
    array $counts = [],
    ?int $baseline = 1,
    array $baselineExecutions = [],
    array $candidates = [],
): GateEvidence {
    return new GateEvidence(
        scanCompleted: $completed,
        executions: $executions ?? ['semgrep' => qgExec(coverage: AnalyzerCoverage::explicit(['R1'])), 'composer-audit' => qgExec()],
        openCountsBySeverity: $counts,
        openFindingIdsBySeverity: array_map(fn (int $n) => array_map(fn ($i) => "ID{$i}", range(1, min($n, 3))), array_filter($counts)),
        baselineScanId: $baseline,
        baselineExecutions: $baselineExecutions,
        newCandidates: $candidates,
    );
}

function qgEval(QualityGatePolicy $policy, GateEvidence $evidence): QualityGateEvaluation
{
    return (new QualityGateEvaluator)->evaluate($policy, $evidence);
}

function qgMax(array $limits): QualityGatePolicy
{
    return new QualityGatePolicy([new MaxOpenFindingsRule($limits)]);
}

function qgCandidate(Severity $severity, bool $first = true, string $analyzer = 'semgrep', string $rule = 'R1', string $id = 'F1'): NewFindingCandidate
{
    return new NewFindingCandidate($id, $severity, $analyzer, $rule, $first);
}

// ---------------- max-open-findings ----------------

it('passes at the boundary (count == max) and fails just over it (count > max)', function () {
    $policy = qgMax(['high' => 2]);

    expect(qgEval($policy, qgEvidence(counts: ['high' => 2]))->outcome)->toBe(O::Passed)
        ->and(qgEval($policy, qgEvidence(counts: ['high' => 3]))->outcome)->toBe(O::Failed)
        ->and(qgEval($policy, qgEvidence(counts: ['high' => 0]))->outcome)->toBe(O::Passed);
});

it('enforces each severity threshold independently (Critical, High, Medium, Low, Info)', function (string $severity) {
    $policy = qgMax([$severity => 1]);

    expect(qgEval($policy, qgEvidence(counts: [$severity => 1]))->outcome)->toBe(O::Passed)
        ->and(qgEval($policy, qgEvidence(counts: [$severity => 2]))->outcome)->toBe(O::Failed)
        // findings of OTHER severities never count against this one
        ->and(qgEval($policy, qgEvidence(counts: ['unknown' => 50]))->outcome)->toBe(O::Passed);
})->with(['critical', 'high', 'medium', 'low', 'info']);

it('treats a limit of 0 as an enforced "none allowed", unlike an absent severity', function () {
    $zero = qgMax(['critical' => 0]);

    expect(qgEval($zero, qgEvidence(counts: ['critical' => 1]))->outcome)->toBe(O::Failed)
        ->and(qgEval($zero, qgEvidence(counts: ['high' => 99]))->outcome)->toBe(O::Passed)
        ->and(qgEval($zero, qgEvidence())->ruleResults)->toHaveCount(1);
});

it('treats Unknown severity as its own bucket: enforced only when configured, never folded into another', function () {
    $critical = qgMax(['critical' => 0]);
    $unknown = qgMax(['unknown' => 0]);

    expect(qgEval($critical, qgEvidence(counts: ['unknown' => 4]))->outcome)->toBe(O::Passed)
        ->and(qgEval($unknown, qgEvidence(counts: ['unknown' => 4]))->outcome)->toBe(O::Failed);
});

it('reports observed/expected values and a bounded set of finding ids for a violation', function () {
    $result = qgEval(qgMax(['high' => 0]), qgEvidence(counts: ['high' => 30]))->ruleResults[0];

    expect($result->ruleId)->toBe(GateRuleId::MaxOpenFindings)
        ->and($result->subject)->toBe('high')
        ->and($result->observed)->toBe('30')
        ->and($result->expected)->toBe('<= 0')
        ->and($result->findingCount)->toBe(30)
        ->and(count($result->findingIds))->toBeLessThanOrEqual(25);
});

it('is Indeterminate (not Passed) when a count is within its limit but an analyzer did not verify the audit', function (ExecutionStatus $status) {
    $evidence = qgEvidence(executions: ['semgrep' => qgExec($status), 'composer-audit' => qgExec()], counts: ['high' => 0]);

    expect(qgEval(qgMax(['high' => 0]), $evidence)->outcome)->toBe(O::Indeterminate);
})->with([ExecutionStatus::TimedOut, ExecutionStatus::Failed, ExecutionStatus::Unavailable, ExecutionStatus::Skipped]);

it('still Fails on a proven violation even when other evidence is incomplete', function () {
    $evidence = qgEvidence(executions: ['semgrep' => qgExec(ExecutionStatus::TimedOut)], counts: ['high' => 5]);

    expect(qgEval(qgMax(['high' => 0]), $evidence)->outcome)->toBe(O::Failed);
});

it('ignores NotApplicable analyzers as evidence gaps (the stack never called for them)', function () {
    $evidence = qgEvidence(executions: ['npm-audit' => qgExec(ExecutionStatus::NotApplicable), 'composer-audit' => qgExec()], counts: []);

    expect(qgEval(qgMax(['high' => 0]), $evidence)->outcome)->toBe(O::Passed);
});

it('does not treat Passed-with-Unknown-coverage as a gap for count rules (coverage is its own rule)', function () {
    $evidence = qgEvidence(executions: ['composer-audit' => qgExec(coverage: AnalyzerCoverage::unknown())]);

    expect(qgEval(qgMax(['critical' => 0]), $evidence)->outcome)->toBe(O::Passed);
});

it('is Indeterminate for absence-based conclusions when the scan itself did not complete', function () {
    $evidence = qgEvidence(completed: false, executions: [], counts: []);

    expect(qgEval(qgMax(['high' => 0]), $evidence)->outcome)->toBe(O::Indeterminate);
});

// ---------------- no-new-severity ----------------

function qgNoNew(Severity $min = Severity::High): QualityGatePolicy
{
    return new QualityGatePolicy([new NoNewSeverityRule($min)]);
}

it('is Indeterminate without a baseline (nothing to compare against)', function () {
    $result = qgEval(qgNoNew(), qgEvidence(baseline: null))->ruleResults[0];

    expect($result->outcome)->toBe(O::Indeterminate)
        ->and($result->summary)->toContain('No baseline');
});

it('passes when there is a baseline and nothing new at or above the threshold', function () {
    expect(qgEval(qgNoNew(), qgEvidence(baseline: 7, candidates: [qgCandidate(Severity::Medium)]))->outcome)->toBe(O::Passed);
});

it('fails on a definitely-new finding at or above the threshold, counting only those', function () {
    $evidence = qgEvidence(baseline: 7, candidates: [
        qgCandidate(Severity::Critical, id: 'A'),
        qgCandidate(Severity::High, id: 'B'),
        qgCandidate(Severity::Medium, id: 'C'), // below threshold
    ]);

    $result = qgEval(qgNoNew(), $evidence)->ruleResults[0];

    expect($result->outcome)->toBe(O::Failed)
        ->and($result->findingCount)->toBe(2)
        ->and($result->findingIds)->toBe(['A', 'B'])
        ->and($result->observed)->toBe('2');
});

it('counts an Unknown-severity new finding as meeting the threshold (fail closed)', function () {
    $evidence = qgEvidence(baseline: 7, candidates: [qgCandidate(Severity::Unknown)]);

    expect(qgEval(qgNoNew(Severity::Critical), $evidence)->outcome)->toBe(O::Failed);
});

it('fails on a regression only when the baseline actually verified that rule', function () {
    $baseline = ['semgrep' => qgExec(ExecutionStatus::Passed, AnalyzerCoverage::explicit(['R1']))];
    $candidate = qgCandidate(Severity::High, first: false, analyzer: 'semgrep', rule: 'R1');

    expect(qgEval(qgNoNew(), qgEvidence(baseline: 7, baselineExecutions: $baseline, candidates: [$candidate]))->outcome)->toBe(O::Failed);
});

it('is Indeterminate, not Failed and not Passed, when a reappeared finding cannot be proven a regression', function (array $baseline) {
    $candidate = qgCandidate(Severity::High, first: false, analyzer: 'semgrep', rule: 'R1');

    expect(qgEval(qgNoNew(), qgEvidence(baseline: 7, baselineExecutions: $baseline, candidates: [$candidate]))->outcome)->toBe(O::Indeterminate);
})->with([
    'baseline coverage Unknown' => [['semgrep' => new ExecutionEvidence(ExecutionStatus::Passed, AnalyzerCoverage::unknown())]],
    'baseline rule not covered' => [['semgrep' => new ExecutionEvidence(ExecutionStatus::Passed, AnalyzerCoverage::explicit(['OTHER']))]],
    'baseline analyzer timed out' => [['semgrep' => new ExecutionEvidence(ExecutionStatus::TimedOut, AnalyzerCoverage::explicit(['R1']))]],
    'baseline had no such analyzer' => [[]],
]);

it('is Indeterminate when nothing new is proven but the current audit had an evidence gap', function () {
    $evidence = qgEvidence(executions: ['semgrep' => qgExec(ExecutionStatus::TimedOut)], baseline: 7);

    expect(qgEval(qgNoNew(), $evidence)->outcome)->toBe(O::Indeterminate);
});

// ---------------- analyzer-status ----------------

function qgStatus(string ...$analyzers): QualityGatePolicy
{
    return new QualityGatePolicy([new AnalyzerStatusRule(array_values($analyzers))]);
}

it('judges a required analyzer by its execution status', function (ExecutionStatus $status, O $expected) {
    $evidence = qgEvidence(executions: ['semgrep' => qgExec($status)]);

    expect(qgEval(qgStatus('semgrep'), $evidence)->outcome)->toBe($expected);
})->with([
    'passed → pass' => [ExecutionStatus::Passed, O::Passed],
    'failed → fail' => [ExecutionStatus::Failed, O::Failed],
    'timed out → fail' => [ExecutionStatus::TimedOut, O::Failed],
    'unavailable → fail' => [ExecutionStatus::Unavailable, O::Failed],
    'not applicable → indeterminate' => [ExecutionStatus::NotApplicable, O::Indeterminate],
    'skipped → indeterminate' => [ExecutionStatus::Skipped, O::Indeterminate],
]);

it('is Indeterminate when a required analyzer has no recorded execution', function () {
    expect(qgEval(qgStatus('semgrep'), qgEvidence(executions: []))->outcome)->toBe(O::Indeterminate);
});

it('produces one result per required analyzer', function () {
    $evidence = qgEvidence(executions: ['semgrep' => qgExec(), 'composer-audit' => qgExec(ExecutionStatus::Failed)]);
    $results = qgEval(qgStatus('semgrep', 'composer-audit'), $evidence)->ruleResults;

    expect($results)->toHaveCount(2)
        ->and(array_map(fn ($r) => [$r->subject, $r->outcome], $results))->toBe([
            ['composer-audit', O::Failed],
            ['semgrep', O::Passed],
        ]);
});

// ---------------- analyzer-coverage ----------------

function qgCoverage(string $analyzer, CoverageRequirement $requirement): QualityGatePolicy
{
    return new QualityGatePolicy([new AnalyzerCoverageRule([$analyzer => $requirement])]);
}

it('judges required coverage from the execution’s declared coverage', function (AnalyzerCoverage $coverage, CoverageRequirement $need, O $expected) {
    $evidence = qgEvidence(executions: ['semgrep' => qgExec(ExecutionStatus::Passed, $coverage)]);

    expect(qgEval(qgCoverage('semgrep', $need), $evidence)->outcome)->toBe($expected);
})->with([
    'explicit satisfies explicit-or-full' => [AnalyzerCoverage::explicit(['R']), CoverageRequirement::ExplicitOrFull, O::Passed],
    'full satisfies explicit-or-full' => [AnalyzerCoverage::full(), CoverageRequirement::ExplicitOrFull, O::Passed],
    'unknown does not (a violation)' => [AnalyzerCoverage::unknown(), CoverageRequirement::ExplicitOrFull, O::Failed],
    'explicit does not satisfy full' => [AnalyzerCoverage::explicit(['R']), CoverageRequirement::Full, O::Failed],
    'full satisfies full' => [AnalyzerCoverage::full(), CoverageRequirement::Full, O::Passed],
]);

it('fails a coverage requirement for an analyzer that did not complete (no evidence was produced)', function (ExecutionStatus $status) {
    $evidence = qgEvidence(executions: ['semgrep' => qgExec($status, AnalyzerCoverage::unknown())]);

    expect(qgEval(qgCoverage('semgrep', CoverageRequirement::ExplicitOrFull), $evidence)->outcome)->toBe(O::Failed);
})->with([ExecutionStatus::Failed, ExecutionStatus::TimedOut, ExecutionStatus::Unavailable]);

it('is Indeterminate for coverage when the analyzer produced no usable result', function (?ExecutionStatus $status) {
    $executions = $status === null ? [] : ['semgrep' => qgExec($status)];

    expect(qgEval(qgCoverage('semgrep', CoverageRequirement::ExplicitOrFull), qgEvidence(executions: $executions))->outcome)->toBe(O::Indeterminate);
})->with([null, ExecutionStatus::NotApplicable, ExecutionStatus::Skipped]);

it('never pretends composer/npm can satisfy a coverage requirement they cannot provide', function () {
    $evidence = qgEvidence(executions: ['composer-audit' => qgExec(ExecutionStatus::Passed, AnalyzerCoverage::unknown())]);

    expect(qgEval(qgCoverage('composer-audit', CoverageRequirement::ExplicitOrFull), $evidence)->outcome)->toBe(O::Failed);
});

// ---------------- combining, determinism ----------------

it('combines rules with Failed > Indeterminate > Passed and counts them', function () {
    $policy = new QualityGatePolicy([
        new MaxOpenFindingsRule(['high' => 0]),
        new AnalyzerStatusRule(['semgrep']),
        new NoNewSeverityRule(Severity::High),
    ]);
    $evidence = qgEvidence(executions: ['semgrep' => qgExec(ExecutionStatus::Failed)], counts: ['high' => 0], baseline: null);

    $evaluation = qgEval($policy, $evidence);

    expect($evaluation->outcome)->toBe(O::Failed)
        ->and($evaluation->countWhere(O::Failed))->toBe(1)
        ->and($evaluation->countWhere(O::Indeterminate))->toBeGreaterThanOrEqual(1)
        ->and($evaluation->ruleResults)->toHaveCount(3);
});

it('is deterministic: identical inputs give identical verdicts', function () {
    $policy = new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 1, 'critical' => 0]), new AnalyzerStatusRule(['semgrep'])]);
    $evidence = qgEvidence(counts: ['high' => 2]);

    expect(serialize(qgEval($policy, $evidence)))->toBe(serialize(qgEval($policy, $evidence)));
});

it('never yields a Pass for a policy with no rules', function () {
    expect(qgEval(new QualityGatePolicy([]), qgEvidence())->outcome)->toBe(O::Indeterminate);
});
