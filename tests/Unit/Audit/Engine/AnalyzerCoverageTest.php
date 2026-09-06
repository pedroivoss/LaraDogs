<?php

use App\Audit\Discovery\ProjectDiscovery;
use App\Audit\Engine\Execution\AnalyzerCoverage;
use App\Audit\Engine\Execution\AnalyzerExecution;
use App\Audit\Engine\Execution\AnalyzerResult;
use App\Audit\Engine\Execution\CoverageMode;
use App\Audit\Engine\Plan\AuditPlanItem;
use Tests\Support\Engine\Analyzers\NotApplicableAnalyzer;

it('never verifies anything under UNKNOWN coverage', function () {
    $coverage = AnalyzerCoverage::unknown();

    expect($coverage->mode)->toBe(CoverageMode::Unknown);
    expect($coverage->verifies('any-rule'))->toBeFalse();
});

it('only verifies rule ids explicitly listed under EXPLICIT coverage', function () {
    $coverage = AnalyzerCoverage::explicit(['LARA-SEC-023', 'LARA-SEC-024']);

    expect($coverage->verifies('LARA-SEC-023'))->toBeTrue();
    expect($coverage->verifies('LARA-SEC-024'))->toBeTrue();
    expect($coverage->verifies('LARA-SEC-999'))->toBeFalse();
});

it('verifies any rule id under FULL coverage', function () {
    $coverage = AnalyzerCoverage::full();

    expect($coverage->verifies('anything-at-all'))->toBeTrue();
});

it('never lets ruleset_version influence verifies(), regardless of mode', function () {
    $withVersion = AnalyzerCoverage::explicit(['LARA-SEC-023'], rulesetVersion: '2026.09.1');
    $withoutVersion = AnalyzerCoverage::explicit(['LARA-SEC-023']);

    expect($withVersion->verifies('LARA-SEC-023'))->toBe($withoutVersion->verifies('LARA-SEC-023'));
    expect($withVersion->rulesetVersion)->toBe('2026.09.1');
});

it('defaults AnalyzerResult coverage to UNKNOWN when the analyzer declares none', function () {
    $result = AnalyzerResult::passed('ok');

    expect($result->coverage->mode)->toBe(CoverageMode::Unknown);
});

it('carries an analyzer\'s explicit coverage through AnalyzerResult', function () {
    $result = AnalyzerResult::passed('ok', coverage: AnalyzerCoverage::full());

    expect($result->coverage->mode)->toBe(CoverageMode::Full);
});

it('reports UNKNOWN coverage for an AnalyzerExecution that never ran', function () {
    $analyzer = new NotApplicableAnalyzer;
    $item = AuditPlanItem::notApplicable($analyzer, $analyzer->applicability(
        (new ProjectDiscovery)->discover(dirname(__DIR__, 3).'/Fixtures/discovery/laravel-blade')->profile,
    ));

    $execution = AnalyzerExecution::fromPlanItem($item);

    expect($execution->coverage()->mode)->toBe(CoverageMode::Unknown);
});
