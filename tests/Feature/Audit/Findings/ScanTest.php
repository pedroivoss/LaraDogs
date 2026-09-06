<?php

use App\Audit\Discovery\Profile\ProjectProfile;
use App\Audit\Discovery\ProjectDiscovery;
use App\Audit\Findings\ScanStatus;
use App\Models\Audit\Project;
use App\Models\Audit\Scan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function discoverProfileForScanTest(): ProjectProfile
{
    $fixture = dirname(__DIR__, 3).'/Fixtures/discovery/laravel-blade';
    $result = (new ProjectDiscovery)->discover($fixture);

    expect($result->isSuccessful())->toBeTrue();

    return $result->profile;
}

it('persists a ProjectProfile snapshot on the scan', function () {
    $project = Project::query()->create(['name' => 'Example', 'path' => '/workspace/example']);
    $profile = discoverProfileForScanTest();

    $scan = Scan::query()->create([
        'project_id' => $project->id,
        'status' => ScanStatus::Running,
        'started_at' => now(),
        'project_profile' => $profile,
    ]);

    $fromDb = Scan::query()->find($scan->id);

    expect($fromDb->project_profile)->toBeArray();
    expect($fromDb->project_profile['project']['type'])->toBe('laravel');
});

it('has its own public id separate from its internal id and from the project', function () {
    $project = Project::query()->create(['name' => 'Example', 'path' => '/workspace/example']);
    $scan = Scan::query()->create([
        'project_id' => $project->id,
        'status' => ScanStatus::Running,
        'started_at' => now(),
        'project_profile' => discoverProfileForScanTest(),
    ]);

    expect($scan->public_id)->toBeString()->not->toBeEmpty();
    expect($scan->public_id)->not->toBe($project->public_id);
});

it('records started_at/finished_at/status/duration independently for each scan', function () {
    $project = Project::query()->create(['name' => 'Example', 'path' => '/workspace/example']);
    $profile = discoverProfileForScanTest();

    $first = Scan::query()->create([
        'project_id' => $project->id,
        'status' => ScanStatus::Completed,
        'started_at' => now(),
        'finished_at' => now(),
        'duration_ms' => 120,
        'project_profile' => $profile,
    ]);

    $second = Scan::query()->create([
        'project_id' => $project->id,
        'status' => ScanStatus::Running,
        'started_at' => now(),
        'project_profile' => $profile,
    ]);

    expect($first->id)->not->toBe($second->id);
    expect($first->status)->toBe(ScanStatus::Completed);
    expect($second->status)->toBe(ScanStatus::Running);
    expect($second->finished_at)->toBeNull();
    expect($project->scans()->count())->toBe(2);
});
