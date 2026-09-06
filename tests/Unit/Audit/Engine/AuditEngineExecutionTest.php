<?php

use App\Audit\Discovery\ProjectDiscovery;
use App\Audit\Engine\AuditContext;
use App\Audit\Engine\AuditEngine;
use App\Audit\Engine\AuditExecutionSettings;
use App\Audit\Engine\Execution\AnalyzerExecution;
use App\Audit\Engine\Execution\ExecutionStatus;
use App\Audit\Engine\Registry\AnalyzerRegistry;
use Tests\Support\Engine\Analyzers\AlwaysFailAnalyzer;
use Tests\Support\Engine\Analyzers\AlwaysPassAnalyzer;
use Tests\Support\Engine\Analyzers\NotApplicableAnalyzer;
use Tests\Support\Engine\Analyzers\SpyAnalyzer;
use Tests\Support\Engine\Analyzers\ThrowingAnalyzer;
use Tests\Support\Engine\Analyzers\TimedOutAnalyzer;
use Tests\Support\Engine\Analyzers\UnavailableAnalyzer;

function executionContext(bool $continueOnFailure = true): AuditContext
{
    $result = (new ProjectDiscovery)->discover(dirname(__DIR__, 3).'/Fixtures/discovery/laravel-blade');

    return new AuditContext(
        runId: 'exec-test',
        projectPath: $result->path,
        profile: $result->profile,
        settings: new AuditExecutionSettings(continueOnFailure: $continueOnFailure),
    );
}

it('normalizes a successful analyzer run', function () {
    $registry = new AnalyzerRegistry;
    $registry->register(new AlwaysPassAnalyzer);

    $result = (new AuditEngine($registry))->run(executionContext());

    expect($result->executions)->toHaveCount(1);
    expect($result->executions[0]->status)->toBe(ExecutionStatus::Passed);
    expect($result->executions[0]->result?->summary)->toBe('Always passes.');
    expect($result->executions[0]->durationMs)->toBeGreaterThanOrEqual(0);
});

it('normalizes an analyzer-reported failure', function () {
    $registry = new AnalyzerRegistry;
    $registry->register(new AlwaysFailAnalyzer);

    $result = (new AuditEngine($registry))->run(executionContext());

    expect($result->executions[0]->status)->toBe(ExecutionStatus::Failed);
    expect($result->executions[0]->result?->summary)->toBe('Always fails, on purpose.');
});

it('normalizes a thrown exception into a Failed execution without aborting the run', function () {
    $registry = new AnalyzerRegistry;
    $registry->register(new ThrowingAnalyzer('throws'));
    $registry->register(new AlwaysPassAnalyzer('after'));

    $result = (new AuditEngine($registry))->run(executionContext());

    expect($result->executions)->toHaveCount(2);
    expect($result->executions[0]->status)->toBe(ExecutionStatus::Failed);
    expect($result->executions[0]->result?->diagnostics)->toHaveCount(1);
    expect($result->executions[0]->result?->diagnostics[0]->message)->toContain('RuntimeException');
    expect($result->executions[0]->result?->diagnostics[0]->message)->toContain('Simulated internal analyzer failure.');
    // The analyzer after the throwing one must still run — the exception
    // must not have aborted the whole engine run.
    expect($result->executions[1]->status)->toBe(ExecutionStatus::Passed);
});

it('normalizes a simulated timeout', function () {
    $registry = new AnalyzerRegistry;
    $registry->register(new TimedOutAnalyzer);

    $result = (new AuditEngine($registry))->run(executionContext());

    expect($result->executions[0]->status)->toBe(ExecutionStatus::TimedOut);
});

it('does not call run() for an unavailable analyzer', function () {
    $registry = new AnalyzerRegistry;
    $registry->register(new UnavailableAnalyzer);

    $result = (new AuditEngine($registry))->run(executionContext());

    expect($result->executions[0]->status)->toBe(ExecutionStatus::Unavailable);
    expect($result->executions[0]->result)->toBeNull();
    expect($result->executions[0]->durationMs)->toBeNull();
});

it('does not call availability() or run() for a not-applicable analyzer', function () {
    $registry = new AnalyzerRegistry;
    $registry->register(new NotApplicableAnalyzer);

    $result = (new AuditEngine($registry))->run(executionContext());

    expect($result->executions[0]->status)->toBe(ExecutionStatus::NotApplicable);
    expect($result->executions[0]->result)->toBeNull();
});

it('continues executing subsequent analyzers after a failure by default (continue_on_failure=true)', function () {
    $registry = new AnalyzerRegistry;
    $registry->register(new AlwaysFailAnalyzer('first'));
    $registry->register(new AlwaysPassAnalyzer('second'));

    $result = (new AuditEngine($registry))->run(executionContext(continueOnFailure: true));

    expect($result->executions[0]->status)->toBe(ExecutionStatus::Failed);
    expect($result->executions[1]->status)->toBe(ExecutionStatus::Passed);
});

it('skips subsequent planned analyzers after a failure when continue_on_failure=false', function () {
    $registry = new AnalyzerRegistry;
    $registry->register(new AlwaysFailAnalyzer('first'));
    $registry->register(new AlwaysPassAnalyzer('second'));
    $registry->register(new AlwaysPassAnalyzer('third'));

    $result = (new AuditEngine($registry))->run(executionContext(continueOnFailure: false));

    expect($result->executions[0]->status)->toBe(ExecutionStatus::Failed);
    expect($result->executions[1]->status)->toBe(ExecutionStatus::Skipped);
    expect($result->executions[2]->status)->toBe(ExecutionStatus::Skipped);
});

it('does not skip not-applicable/unavailable items just because fail-fast triggered', function () {
    $registry = new AnalyzerRegistry;
    $registry->register(new AlwaysFailAnalyzer('first'));
    $registry->register(new NotApplicableAnalyzer);
    $registry->register(new UnavailableAnalyzer);

    $result = (new AuditEngine($registry))->run(executionContext(continueOnFailure: false));

    expect($result->executions[1]->status)->toBe(ExecutionStatus::NotApplicable);
    expect($result->executions[2]->status)->toBe(ExecutionStatus::Unavailable);
});

it('calls each analyzer hook exactly once per its lifecycle stage, never more', function () {
    $spy = new SpyAnalyzer;
    $registry = new AnalyzerRegistry;
    $registry->register($spy);

    (new AuditEngine($registry))->run(executionContext());

    expect($spy->applicabilityCalls)->toBe(1);
    expect($spy->availabilityCalls)->toBe(1);
    expect($spy->runCalls)->toBe(1);
});

it('produces deterministic execution order and content across repeated runs (ignoring wall-clock fields)', function () {
    $buildRegistry = function () {
        $registry = new AnalyzerRegistry;
        $registry->register(new AlwaysPassAnalyzer('one'));
        $registry->register(new AlwaysFailAnalyzer('two'));
        $registry->register(new NotApplicableAnalyzer);
        $registry->register(new UnavailableAnalyzer);

        return $registry;
    };

    $normalize = fn (array $executions) => array_map(
        fn (AnalyzerExecution $e) => [(string) $e->id, $e->status->value, $e->result?->summary],
        $executions,
    );

    $resultA = (new AuditEngine($buildRegistry()))->run(executionContext());
    $resultB = (new AuditEngine($buildRegistry()))->run(executionContext());

    expect($normalize($resultA->executions))->toBe($normalize($resultB->executions));
});
