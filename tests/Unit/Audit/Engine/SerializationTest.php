<?php

use App\Audit\Discovery\ProjectDiscovery;
use App\Audit\Engine\AuditContext;
use App\Audit\Engine\AuditEngine;
use App\Audit\Engine\Registry\AnalyzerRegistry;
use Tests\Support\Engine\Analyzers\AlwaysFailAnalyzer;
use Tests\Support\Engine\Analyzers\AlwaysPassAnalyzer;
use Tests\Support\Engine\Analyzers\NotApplicableAnalyzer;
use Tests\Support\Engine\Analyzers\UnavailableAnalyzer;

function serializationContext(): AuditContext
{
    $result = (new ProjectDiscovery)->discover(dirname(__DIR__, 3).'/Fixtures/discovery/laravel-blade');

    return new AuditContext(runId: 'serialize-test', projectPath: $result->path, profile: $result->profile);
}

it('serializes an AuditPlan to a stable, inspectable array shape', function () {
    $registry = new AnalyzerRegistry;
    $registry->register(new AlwaysPassAnalyzer('a'));
    $registry->register(new NotApplicableAnalyzer);
    $registry->register(new UnavailableAnalyzer);

    $plan = (new AuditEngine($registry))->plan(serializationContext());

    $decoded = json_decode(json_encode($plan), true);

    expect($decoded)->toHaveKey('items');
    expect($decoded['items'])->toHaveCount(3);
    expect($decoded['items'][0])->toHaveKeys(['id', 'name', 'category', 'applicability', 'availability', 'status']);
    expect($decoded['items'][0]['status'])->toBe('planned');
    expect($decoded['items'][1]['status'])->toBe('not_applicable');
    expect($decoded['items'][1]['availability'])->toBeNull();
    expect($decoded['items'][2]['status'])->toBe('unavailable');
});

it('serializes an AuditRunResult to a stable, inspectable array shape', function () {
    $registry = new AnalyzerRegistry;
    $registry->register(new AlwaysPassAnalyzer('a'));
    $registry->register(new AlwaysFailAnalyzer('b'));

    $result = (new AuditEngine($registry))->run(serializationContext());

    $decoded = json_decode(json_encode($result), true);

    expect($decoded)->toHaveKeys(['run_id', 'plan', 'executions', 'started_at', 'finished_at', 'duration_ms']);
    expect($decoded['run_id'])->toBe('serialize-test');
    expect($decoded['executions'])->toHaveCount(2);
    expect($decoded['executions'][0])->toHaveKeys(['id', 'name', 'category', 'status', 'result', 'duration_ms', 'note']);
    expect($decoded['executions'][0]['result']['status'])->toBe('passed');
    expect($decoded['executions'][1]['result']['status'])->toBe('failed');
});
