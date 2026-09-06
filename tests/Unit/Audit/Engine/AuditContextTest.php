<?php

use App\Audit\Discovery\ProjectDiscovery;
use App\Audit\Engine\AuditContext;
use App\Audit\Engine\AuditExecutionSettings;

it('carries the exact ProjectProfile produced by Project Discovery through to analyzers', function () {
    $discovery = (new ProjectDiscovery)->discover(dirname(__DIR__, 3).'/Fixtures/discovery/laravel-blade');

    $context = new AuditContext(
        runId: 'context-test',
        projectPath: $discovery->path,
        profile: $discovery->profile,
    );

    expect($context->profile)->toBe($discovery->profile);
    expect($context->profile->type->value)->toBe('laravel');
    expect($context->projectPath)->toBe($discovery->path);
});

it('defaults to sane execution settings', function () {
    $discovery = (new ProjectDiscovery)->discover(dirname(__DIR__, 3).'/Fixtures/discovery/laravel-blade');

    $context = new AuditContext(runId: 'x', projectPath: $discovery->path, profile: $discovery->profile);

    expect($context->settings)->toBeInstanceOf(AuditExecutionSettings::class);
    expect($context->settings->continueOnFailure)->toBeTrue();
    expect($context->settings->defaultTimeoutSeconds)->toBe(30);
});
