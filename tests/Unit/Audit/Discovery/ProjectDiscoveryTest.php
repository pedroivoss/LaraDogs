<?php

use App\Audit\Discovery\DiscoveryResult;
use App\Audit\Discovery\DiscoveryStatus;
use App\Audit\Discovery\Profile\PackageManager;
use App\Audit\Discovery\Profile\ProjectType;
use App\Audit\Discovery\ProjectDiscovery;
use App\Audit\Discovery\Support\DetectionStatus;

function discoveryFixturePath(string $name): string
{
    return dirname(__DIR__, 3).'/Fixtures/discovery/'.$name;
}

function discoverFixture(string $name): DiscoveryResult
{
    return (new ProjectDiscovery)->discover(discoveryFixturePath($name));
}

it('detects a Laravel + Blade project from composer.json/composer.lock and resources/views', function () {
    $profile = discoverFixture('laravel-blade')->profile;

    expect($profile->type)->toBe(ProjectType::Laravel);
    expect($profile->backend->php->constraint)->toBe('^8.3');
    expect($profile->backend->composer->status)->toBe(DetectionStatus::Detected);
    expect($profile->backend->composerLock->status)->toBe(DetectionStatus::Detected);
    expect($profile->backend->laravel->status)->toBe(DetectionStatus::Detected);
    expect($profile->backend->laravel->constraint)->toBe('^13.0');
    expect($profile->backend->laravel->installedVersion)->toBe('13.4.2');
    expect($profile->backend->blade->status)->toBe(DetectionStatus::Detected);
    expect($profile->backend->livewire->status)->toBe(DetectionStatus::NotDetected);
    expect($profile->backend->inertia->status)->toBe(DetectionStatus::NotDetected);
});

it('detects Livewire from composer.json', function () {
    $profile = discoverFixture('laravel-livewire')->profile;

    expect($profile->backend->livewire->status)->toBe(DetectionStatus::Detected);
});

it('detects Inertia + React + TypeScript + Vite together', function () {
    $profile = discoverFixture('laravel-inertia-react-ts')->profile;

    expect($profile->type)->toBe(ProjectType::Laravel);
    expect($profile->backend->inertia->status)->toBe(DetectionStatus::Detected);
    expect($profile->frontend->react->status)->toBe(DetectionStatus::Detected);
    expect($profile->frontend->vue->status)->toBe(DetectionStatus::NotDetected);
    expect($profile->frontend->typescript->status)->toBe(DetectionStatus::Detected);
    expect($profile->frontend->vite->status)->toBe(DetectionStatus::Detected);
    expect($profile->frontend->inertiaClient->status)->toBe(DetectionStatus::Detected);
    expect($profile->frontend->packageManager)->toBe(PackageManager::Npm);
});

it('detects Inertia + Vue with yarn as the package manager', function () {
    $profile = discoverFixture('laravel-inertia-vue')->profile;

    expect($profile->frontend->vue->status)->toBe(DetectionStatus::Detected);
    expect($profile->frontend->react->status)->toBe(DetectionStatus::NotDetected);
    expect($profile->frontend->inertiaClient->status)->toBe(DetectionStatus::Detected);
    expect($profile->frontend->packageManager)->toBe(PackageManager::Yarn);
});

it('detects an API-oriented Laravel project with no Blade and no frontend', function () {
    $profile = discoverFixture('laravel-api')->profile;

    expect($profile->type)->toBe(ProjectType::Laravel);
    expect($profile->backend->packages['sanctum']->status)->toBe(DetectionStatus::Detected);
    expect($profile->backend->blade->status)->toBe(DetectionStatus::NotDetected);
    expect($profile->frontend->node->status)->toBe(DetectionStatus::NotDetected);
});

it('classifies a plain PHP Composer project as not Laravel', function () {
    $profile = discoverFixture('plain-php-composer')->profile;

    expect($profile->type)->toBe(ProjectType::PlainPhpComposer);
    expect($profile->backend->laravel->status)->toBe(DetectionStatus::NotDetected);
});

it('classifies a Node-only project', function () {
    $profile = discoverFixture('node-only')->profile;

    expect($profile->type)->toBe(ProjectType::NodeOnly);
    expect($profile->frontend->react->status)->toBe(DetectionStatus::Detected);
    expect($profile->backend->composer->status)->toBe(DetectionStatus::NotDetected);
});

it('classifies a genuinely empty directory as empty, not unknown', function () {
    $profile = discoverFixture('empty-directory')->profile;

    expect($profile->type)->toBe(ProjectType::EmptyProject);
});

it('treats a malformed composer.json as invalid rather than crashing, and records an issue', function () {
    $result = discoverFixture('malformed-composer');
    $profile = $result->profile;

    expect($result->isSuccessful())->toBeTrue();
    expect($profile->type)->toBe(ProjectType::Unknown);
    expect($profile->backend->composer->status)->toBe(DetectionStatus::Invalid);
    expect($profile->backend->laravel->status)->toBe(DetectionStatus::Invalid);
    expect($profile->issues)->toHaveCount(1);
    expect($profile->issues[0]->source)->toBe('composer.json');
});

it('treats a malformed package.json as invalid rather than crashing, and records an issue', function () {
    $profile = discoverFixture('malformed-package')->profile;

    expect($profile->type)->toBe(ProjectType::Unknown);
    expect($profile->frontend->node->status)->toBe(DetectionStatus::Invalid);
    expect($profile->issues)->toHaveCount(1);
    expect($profile->issues[0]->source)->toBe('package.json');
});

it('prefers the installed version from composer.lock over the composer.json constraint', function () {
    $profile = discoverFixture('laravel-with-composer-lock')->profile;

    expect($profile->backend->laravel->constraint)->toBe('^13.0');
    expect($profile->backend->laravel->installedVersion)->toBe('13.9.0');
});

it('falls back to the composer.json constraint when there is no composer.lock', function () {
    $profile = discoverFixture('laravel-without-composer-lock')->profile;

    expect($profile->backend->composerLock->status)->toBe(DetectionStatus::NotDetected);
    expect($profile->backend->laravel->constraint)->toBe('^13.0');
    expect($profile->backend->laravel->installedVersion)->toBeNull();
});

it('detects testing stack, infrastructure, and database hints together', function () {
    $profile = discoverFixture('laravel-full-stack')->profile;

    expect($profile->testing->pest->status)->toBe(DetectionStatus::Detected);
    expect($profile->backend->packages['horizon']->status)->toBe(DetectionStatus::Detected);
    expect($profile->infrastructure->docker->status)->toBe(DetectionStatus::Detected);
    expect($profile->infrastructure->dockerCompose->status)->toBe(DetectionStatus::Detected);
    expect($profile->infrastructure->githubActions->status)->toBe(DetectionStatus::Detected);
    expect($profile->infrastructure->redisHints->status)->toBe(DetectionStatus::Detected);
    expect($profile->infrastructure->queueHints->status)->toBe(DetectionStatus::Detected);
    expect($profile->infrastructure->schedulerHints->status)->toBe(DetectionStatus::Detected);
    expect($profile->database->detectedDrivers())->toBe(['pgsql']);
});

it('reports database drivers as unknown, not not-detected, when there is no .env.example', function () {
    $profile = discoverFixture('laravel-blade')->profile;

    expect($profile->database->detectedDrivers())->toBe([]);
    expect($profile->database->drivers['sqlite']->status)->toBe(DetectionStatus::Unknown);
    expect($profile->database->drivers['mysql']->status)->toBe(DetectionStatus::Unknown);
});

it('reports a clear failure for a nonexistent directory instead of throwing', function () {
    $result = (new ProjectDiscovery)->discover(discoveryFixturePath('does-not-exist'));

    expect($result->isSuccessful())->toBeFalse();
    expect($result->status)->toBe(DiscoveryStatus::PathNotFound);
    expect($result->profile)->toBeNull();
});

it('reports a clear failure when the path is a file, not a directory', function () {
    $result = (new ProjectDiscovery)->discover(discoveryFixturePath('laravel-blade').'/composer.json');

    expect($result->isSuccessful())->toBeFalse();
    expect($result->status)->toBe(DiscoveryStatus::PathNotDirectory);
});

it('produces deterministic output across repeated runs against the same project', function () {
    $a = discoverFixture('laravel-inertia-react-ts');
    $b = discoverFixture('laravel-inertia-react-ts');

    expect(json_encode($a))->toBe(json_encode($b));
});
