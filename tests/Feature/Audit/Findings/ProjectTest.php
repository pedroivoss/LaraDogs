<?php

use App\Models\Audit\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('creates a project with a stable public id separate from its internal id', function () {
    $project = Project::query()->create([
        'name' => 'Example App',
        'path' => '/workspace/example-app',
    ]);

    expect($project->id)->toBeInt();
    expect($project->public_id)->toBeString()->not->toBeEmpty();
    expect($project->public_id)->not->toBe((string) $project->id);
});

it('reads a project back with its attributes intact', function () {
    $project = Project::query()->create([
        'name' => 'Example App',
        'path' => '/workspace/example-app',
    ]);

    $fromDb = Project::query()->find($project->id);

    expect($fromDb)->not->toBeNull();
    expect($fromDb->name)->toBe('Example App');
    expect($fromDb->path)->toBe('/workspace/example-app');
    expect($fromDb->public_id)->toBe($project->public_id);
});

it('assigns a different public id to each project', function () {
    $a = Project::query()->create(['name' => 'A', 'path' => '/a']);
    $b = Project::query()->create(['name' => 'B', 'path' => '/b']);

    expect($a->public_id)->not->toBe($b->public_id);
});
