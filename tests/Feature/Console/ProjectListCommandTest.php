<?php

use App\Audit\Projects\RegisterProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('prints a friendly message when no projects are registered yet', function () {
    $this->artisan('laradogs:project:list')
        ->assertExitCode(0)
        ->expectsOutputToContain('No projects registered yet');
});

it('lists registered projects with name/path/last-scan/open-findings columns', function () {
    $path = dirname(__DIR__, 2).'/Fixtures/discovery/laravel-blade';
    (new RegisterProject)->register($path, 'My Project');

    // Table cell text can wrap across output lines depending on terminal
    // width, so assert on the (short, unwrappable) project name rather
    // than a longer phrase — the "never scanned"/open-count semantics
    // themselves are asserted precisely via the --json output below.
    $this->artisan('laradogs:project:list')
        ->assertExitCode(0)
        ->expectsOutputToContain('My Project');
});

it('outputs valid JSON listing every project', function () {
    $path = dirname(__DIR__, 2).'/Fixtures/discovery/laravel-blade';
    (new RegisterProject)->register($path, 'My Project');

    Artisan::call('laradogs:project:list', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), associative: true);

    expect($decoded['projects'])->toHaveCount(1)
        ->and($decoded['projects'][0]['name'])->toBe('My Project')
        ->and($decoded['projects'][0]['last_scan'])->toBeNull()
        ->and($decoded['projects'][0]['open_findings_count'])->toBe(0);
});
