<?php

use App\Audit\Engine\Registry\AnalyzerRegistry;
use App\Audit\Findings\ScanStatus;
use App\Audit\Projects\RegisterProject;
use App\Models\Audit\Project;
use App\Models\Audit\ProjectActiveScan;
use App\Models\Audit\Scan;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Engine\Analyzers\AlwaysPassAnalyzer;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function registerFixtureProjectForCli(): Project
{
    $path = dirname(__DIR__, 2).'/Fixtures/discovery/laravel-blade';

    return (new RegisterProject)->register($path)->project;
}

function bindFakeRegistryForCli(): void
{
    $registry = new AnalyzerRegistry;
    $registry->register(new AlwaysPassAnalyzer('composer-security'));
    app()->instance(AnalyzerRegistry::class, $registry);
}

it('runs a persisted audit for a registered project and prints a human-readable summary', function () {
    $project = registerFixtureProjectForCli();
    bindFakeRegistryForCli();

    $this->artisan('laradogs:project:audit', ['project' => $project->public_id])
        ->assertExitCode(0)
        ->expectsOutputToContain('completed')
        ->expectsOutputToContain('composer-security');

    expect(Scan::query()->where('project_id', $project->id)->count())->toBe(1);
});

it('outputs valid JSON with the scan and analyzer executions', function () {
    $project = registerFixtureProjectForCli();
    bindFakeRegistryForCli();

    Artisan::call('laradogs:project:audit', ['project' => $project->public_id, '--json' => true]);
    $decoded = json_decode(Artisan::output(), associative: true);

    expect($decoded['succeeded'])->toBeTrue()
        ->and($decoded['scan']['status'])->toBe('completed')
        ->and($decoded['analyzer_executions'])->toHaveCount(1)
        ->and($decoded['analyzer_executions'][0]['analyzer_id'])->toBe('composer-security');
});

it('fails with a clear diagnostic and non-zero exit code for an unknown project id, without a stack trace', function () {
    $this->artisan('laradogs:project:audit', ['project' => 'nonexistent-id'])
        ->assertExitCode(1)
        ->expectsOutputToContain('No project found')
        ->doesntExpectOutputToContain('Stack trace')
        ->doesntExpectOutputToContain('Exception');
});

it('fails with a clear diagnostic when another audit is already running for the project', function () {
    $project = registerFixtureProjectForCli();

    $runningScan = Scan::query()->create([
        'project_id' => $project->id,
        'status' => ScanStatus::Running,
        'started_at' => now(),
        'project_profile' => ['project' => ['type' => 'laravel']],
    ]);
    // The portable mutex row (Phase 7.1.4) — every real Running scan
    // always has one (created atomically by
    // ScanRecorder::enqueueScan()); a raw fixture like this must mirror
    // that invariant for the concurrency guard to see it as active.
    ProjectActiveScan::query()->create(['project_id' => $project->id, 'scan_id' => $runningScan->id]);

    bindFakeRegistryForCli();

    $this->artisan('laradogs:project:audit', ['project' => $project->public_id])
        ->assertExitCode(1)
        ->expectsOutputToContain('already running');

    expect(Scan::query()->where('project_id', $project->id)->count())->toBe(1);
});

it('fails with a clear diagnostic when the project path is no longer available', function () {
    $filesystem = new Filesystem;
    $target = sys_get_temp_dir().'/laradogs-cli-project-disappearing-'.uniqid();
    $filesystem->copyDirectory(dirname(__DIR__, 2).'/Fixtures/discovery/laravel-blade', $target);

    $project = (new RegisterProject)->register($target)->project;
    $filesystem->deleteDirectory($target);

    $this->artisan('laradogs:project:audit', ['project' => $project->public_id])
        ->assertExitCode(1)
        ->expectsOutputToContain('Path not found');
});
