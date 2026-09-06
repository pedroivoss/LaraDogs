<?php

use App\Audit\Discovery\ProjectDiscovery;
use App\Audit\Engine\AuditContext;
use App\Audit\Engine\AuditEngine;
use App\Audit\Engine\Execution\ExecutionStatus;
use App\Audit\Engine\Registry\AnalyzerRegistry;
use Tests\Support\Engine\Analyzers\AlwaysPassAnalyzer;
use Tests\Support\Engine\Analyzers\NotApplicableAnalyzer;
use Tests\Support\Engine\Analyzers\SpyAnalyzer;
use Tests\Support\Engine\Analyzers\UnavailableAnalyzer;

function buildPlanContext(): AuditContext
{
    $result = (new ProjectDiscovery)->discover(dirname(__DIR__, 3).'/Fixtures/discovery/laravel-blade');

    return new AuditContext(runId: 'plan-test', projectPath: $result->path, profile: $result->profile);
}

it('produces a plan item with the correct status for each analyzer outcome', function () {
    $registry = new AnalyzerRegistry;
    $registry->register(new AlwaysPassAnalyzer('planned-one'));
    $registry->register(new NotApplicableAnalyzer);
    $registry->register(new UnavailableAnalyzer);

    $plan = (new AuditEngine($registry))->plan(buildPlanContext());

    expect($plan->items)->toHaveCount(3);
    expect($plan->items[0]->status)->toBe(ExecutionStatus::Planned);
    expect($plan->items[0]->availability)->not->toBeNull();
    expect($plan->items[1]->status)->toBe(ExecutionStatus::NotApplicable);
    expect($plan->items[1]->availability)->toBeNull();
    expect($plan->items[2]->status)->toBe(ExecutionStatus::Unavailable);
    expect($plan->items[2]->availability)->not->toBeNull();
});

it('keeps plan item order identical to registration order', function () {
    $registry = new AnalyzerRegistry;
    $registry->register(new AlwaysPassAnalyzer('c'));
    $registry->register(new AlwaysPassAnalyzer('a'));
    $registry->register(new AlwaysPassAnalyzer('b'));

    $plan = (new AuditEngine($registry))->plan(buildPlanContext());

    expect(array_map(fn ($item) => (string) $item->id, $plan->items))->toBe(['c', 'a', 'b']);
});

it('never calls run() while only building a plan', function () {
    $spy = new SpyAnalyzer;
    $registry = new AnalyzerRegistry;
    $registry->register($spy);

    (new AuditEngine($registry))->plan(buildPlanContext());

    expect($spy->applicabilityCalls)->toBe(1);
    expect($spy->availabilityCalls)->toBe(1);
    expect($spy->runCalls)->toBe(0);
});

it('exposes only the planned (executable) items via toExecute()', function () {
    $registry = new AnalyzerRegistry;
    $registry->register(new AlwaysPassAnalyzer('will-run'));
    $registry->register(new NotApplicableAnalyzer);
    $registry->register(new UnavailableAnalyzer);

    $plan = (new AuditEngine($registry))->plan(buildPlanContext());

    expect($plan->toExecute())->toHaveCount(1);
    expect((string) $plan->toExecute()[0]->id)->toBe('will-run');
});
