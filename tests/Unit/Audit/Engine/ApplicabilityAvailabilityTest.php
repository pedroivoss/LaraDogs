<?php

use App\Audit\Discovery\ProjectDiscovery;
use App\Audit\Engine\AuditContext;
use Tests\Support\Engine\Analyzers\LaravelOnlyAnalyzer;
use Tests\Support\Engine\Analyzers\NodeOnlyAnalyzer;
use Tests\Support\Engine\Analyzers\UnavailableAnalyzer;

function engineFixturePath(string $name): string
{
    return dirname(__DIR__, 3).'/Fixtures/discovery/'.$name;
}

function engineContextFor(string $fixture): AuditContext
{
    $result = (new ProjectDiscovery)->discover(engineFixturePath($fixture));

    expect($result->isSuccessful())->toBeTrue();

    return new AuditContext(runId: 'test-run', projectPath: $result->path, profile: $result->profile);
}

it('is applicable for a Laravel project', function () {
    $context = engineContextFor('laravel-blade');

    $applicability = (new LaravelOnlyAnalyzer)->applicability($context->profile);

    expect($applicability->isApplicable())->toBeTrue();
});

it('is not applicable for a Node-only project', function () {
    $context = engineContextFor('node-only');

    $applicability = (new LaravelOnlyAnalyzer)->applicability($context->profile);

    expect($applicability->isApplicable())->toBeFalse();
    expect($applicability->reason)->not->toBeNull();
});

it('is applicable for a project with package.json (React/Node)', function () {
    $context = engineContextFor('laravel-inertia-react-ts');

    $applicability = (new NodeOnlyAnalyzer)->applicability($context->profile);

    expect($applicability->isApplicable())->toBeTrue();
});

it('is not applicable for a project without package.json', function () {
    $context = engineContextFor('laravel-blade');

    $applicability = (new NodeOnlyAnalyzer)->applicability($context->profile);

    expect($applicability->isApplicable())->toBeFalse();
});

it('distinguishes availability from applicability: applicable but unavailable', function () {
    $context = engineContextFor('laravel-blade');
    $analyzer = new UnavailableAnalyzer;

    $applicability = $analyzer->applicability($context->profile);
    $availability = $analyzer->availability($context);

    expect($applicability->isApplicable())->toBeTrue();
    expect($availability->isAvailable())->toBeFalse();
    expect($availability->reason)->not->toBeNull();
});
