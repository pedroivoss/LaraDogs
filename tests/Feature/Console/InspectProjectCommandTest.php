<?php

use Tests\TestCase;

uses(TestCase::class);

function inspectCommandFixturePath(string $name): string
{
    return dirname(__DIR__, 2).'/Fixtures/discovery/'.$name;
}

it('renders a human-readable profile for a valid project', function () {
    $this->artisan('laradogs:inspect', [
        'path' => inspectCommandFixturePath('laravel-blade'),
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('Type: laravel');
});

it('outputs a JSON profile when --json is passed', function () {
    $this->artisan('laradogs:inspect', [
        'path' => inspectCommandFixturePath('laravel-blade'),
        '--json' => true,
    ])->assertExitCode(0);
});

it('exits with a failure code and a clear message for a nonexistent path', function () {
    $this->artisan('laradogs:inspect', [
        'path' => inspectCommandFixturePath('does-not-exist'),
    ])
        ->assertExitCode(1)
        ->expectsOutputToContain('Path not found');
});
