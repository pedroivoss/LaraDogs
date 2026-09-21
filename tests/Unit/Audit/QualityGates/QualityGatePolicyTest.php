<?php

use App\Audit\Engine\Execution\CoverageMode;
use App\Audit\Findings\Severity;
use App\Audit\QualityGates\Policy\AnalyzerCoverageRule;
use App\Audit\QualityGates\Policy\AnalyzerStatusRule;
use App\Audit\QualityGates\Policy\CoverageRequirement;
use App\Audit\QualityGates\Policy\InvalidQualityGatePolicy;
use App\Audit\QualityGates\Policy\MaxOpenFindingsRule;
use App\Audit\QualityGates\Policy\NoNewSeverityRule;
use App\Audit\QualityGates\Policy\QualityGatePolicy;

function fullPolicy(): QualityGatePolicy
{
    return new QualityGatePolicy([
        new MaxOpenFindingsRule(['critical' => 0, 'high' => 0, 'medium' => 10]),
        new NoNewSeverityRule(Severity::High),
        new AnalyzerStatusRule(['semgrep', 'composer-audit']),
        new AnalyzerCoverageRule(['semgrep' => CoverageRequirement::ExplicitOrFull]),
    ]);
}

it('round-trips a policy through its stored document', function () {
    $policy = fullPolicy();
    $again = QualityGatePolicy::fromArray(json_decode(json_encode($policy->toArray()), true));

    expect($again->toArray())->toBe($policy->toArray());
});

it('stores a canonical, ordered document', function () {
    $doc = fullPolicy()->toArray();

    expect($doc['schema'])->toBe(1)
        ->and(array_column($doc['rules'], 'type'))->toBe([
            'laradogs.gate.max-open-findings',
            'laradogs.gate.no-new-severity',
            'laradogs.gate.analyzer-status',
            'laradogs.gate.analyzer-coverage',
        ])
        ->and($doc['rules'][2]['analyzers'])->toBe(['composer-audit', 'semgrep']);
});

it('keeps a threshold of 0 distinct from a severity with no rule', function () {
    $rule = new MaxOpenFindingsRule(['critical' => 0]);

    expect($rule->limits)->toBe(['critical' => 0])
        ->and(array_key_exists('critical', $rule->limits))->toBeTrue()
        ->and(array_key_exists('high', $rule->limits))->toBeFalse()
        ->and($rule->orderedLimits())->toHaveCount(1);
});

it('orders severity limits most severe first with Unknown last', function () {
    $rule = new MaxOpenFindingsRule(['unknown' => 1, 'low' => 5, 'critical' => 0]);

    expect(array_map(fn ($pair) => $pair[0]->value, $rule->orderedLimits()))->toBe(['critical', 'low', 'unknown']);
});

it('rejects invalid policies', function (callable $make) {
    $make();
})->throws(InvalidQualityGatePolicy::class)->with([
    'no limits' => [fn () => new MaxOpenFindingsRule([])],
    'unknown severity' => [fn () => new MaxOpenFindingsRule(['catastrophic' => 1])],
    'negative limit' => [fn () => new MaxOpenFindingsRule(['high' => -1])],
    'limit too large' => [fn () => new MaxOpenFindingsRule(['high' => 100001])],
    'string limit' => [fn () => new MaxOpenFindingsRule(['high' => '0; DROP TABLE findings'])],
    'unknown as new threshold' => [fn () => new NoNewSeverityRule(Severity::Unknown)],
    'no analyzers' => [fn () => new AnalyzerStatusRule([])],
    'bad analyzer id (path)' => [fn () => new AnalyzerStatusRule(['../../etc/passwd'])],
    'bad analyzer id (shell)' => [fn () => new AnalyzerStatusRule(['semgrep; rm -rf /'])],
    'too many analyzers' => [fn () => new AnalyzerStatusRule(array_map(fn ($i) => "a{$i}", range(1, 21)))],
    'coverage bad value' => [fn () => new AnalyzerCoverageRule(['semgrep' => 'anything'])],
    'duplicate rule types' => [fn () => new QualityGatePolicy([new NoNewSeverityRule(Severity::High), new NoNewSeverityRule(Severity::Low)])],
]);

it('strictly rejects hostile or unknown stored documents', function (array $doc) {
    QualityGatePolicy::fromArray($doc);
})->throws(InvalidQualityGatePolicy::class)->with([
    'wrong schema' => [['schema' => 2, 'rules' => []]],
    'missing schema' => [['rules' => []]],
    'extra top-level key' => [['schema' => 1, 'rules' => [], 'expression' => 'high > 0']],
    'unknown rule type' => [['schema' => 1, 'rules' => [['type' => 'laradogs.gate.eval', 'code' => 'phpinfo();']]]],
    'extra rule key' => [['schema' => 1, 'rules' => [['type' => 'laradogs.gate.no-new-severity', 'min_severity' => 'high', 'sql' => '1=1']]]],
    'rules not a list of objects' => [['schema' => 1, 'rules' => ['laradogs.gate.no-new-severity']]],
    'unknown coverage requirement' => [['schema' => 1, 'rules' => [['type' => 'laradogs.gate.analyzer-coverage', 'requirements' => ['semgrep' => 'always']]]]],
    'too many rules' => [['schema' => 1, 'rules' => array_fill(0, 5, ['type' => 'laradogs.gate.no-new-severity', 'min_severity' => 'high'])]],
]);

it('accepts an empty rule list as a valid (but rule-less) document', function () {
    expect(QualityGatePolicy::fromArray(['schema' => 1, 'rules' => []])->isEmpty())->toBeTrue();
});

it('models coverage requirements without inventing Full support', function () {
    expect(CoverageRequirement::ExplicitOrFull->isSatisfiedBy(CoverageMode::Explicit))->toBeTrue()
        ->and(CoverageRequirement::ExplicitOrFull->isSatisfiedBy(CoverageMode::Full))->toBeTrue()
        ->and(CoverageRequirement::ExplicitOrFull->isSatisfiedBy(CoverageMode::Unknown))->toBeFalse()
        ->and(CoverageRequirement::Full->isSatisfiedBy(CoverageMode::Explicit))->toBeFalse()
        ->and(CoverageRequirement::Full->isSatisfiedBy(CoverageMode::Full))->toBeTrue();
});
