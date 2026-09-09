<?php

use App\Models\Audit\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function projectAddFixturePath(string $name): string
{
    return dirname(__DIR__, 2).'/Fixtures/discovery/'.$name;
}

it('registers a new project and prints a human-readable confirmation', function () {
    $path = projectAddFixturePath('laravel-blade');

    $this->artisan('laradogs:project:add', ['path' => $path])
        ->assertExitCode(0)
        ->expectsOutputToContain('Registered project')
        ->expectsOutputToContain('laravel-blade');

    expect(Project::query()->count())->toBe(1);
});

it('reports already-registered (exit 0, not an error) on a second registration of the same path', function () {
    $path = projectAddFixturePath('laravel-blade');

    Artisan::call('laradogs:project:add', ['path' => $path]);

    $this->artisan('laradogs:project:add', ['path' => $path])
        ->assertExitCode(0)
        ->expectsOutputToContain('Already registered');

    expect(Project::query()->count())->toBe(1);
});

it('outputs valid JSON with the project id/name/path on success', function () {
    $path = projectAddFixturePath('laravel-blade');

    Artisan::call('laradogs:project:add', ['path' => $path, '--json' => true]);
    $decoded = json_decode(Artisan::output(), associative: true);

    expect($decoded['succeeded'])->toBeTrue()
        ->and($decoded['created'])->toBeTrue()
        ->and($decoded['project']['name'])->toBe('laravel-blade')
        ->and($decoded['project']['path'])->toBe(realpath($path))
        ->and($decoded['project']['id'])->not->toBeEmpty();
});

it('fails with a clear diagnostic and a non-zero exit code for a nonexistent path, without a stack trace', function () {
    $this->artisan('laradogs:project:add', ['path' => '/nonexistent/'.uniqid()])
        ->assertExitCode(1)
        ->expectsOutputToContain('Path not found')
        ->doesntExpectOutputToContain('Stack trace')
        ->doesntExpectOutputToContain('Exception');

    expect(Project::query()->count())->toBe(0);
});

it('outputs valid JSON with succeeded=false for an invalid path', function () {
    Artisan::call('laradogs:project:add', ['path' => '/nonexistent/'.uniqid(), '--json' => true]);
    $decoded = json_decode(Artisan::output(), associative: true);

    expect($decoded['succeeded'])->toBeFalse()
        ->and($decoded['error'])->toContain('Path not found');
});

it('accepts a --name option', function () {
    $path = projectAddFixturePath('laravel-blade');

    Artisan::call('laradogs:project:add', ['path' => $path, '--name' => 'Custom Name']);

    expect(Project::query()->first()->name)->toBe('Custom Name');
});
