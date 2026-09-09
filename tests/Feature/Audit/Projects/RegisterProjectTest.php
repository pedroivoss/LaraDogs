<?php

use App\Audit\Discovery\DiscoveryStatus;
use App\Audit\Projects\RegisterProject;
use App\Audit\Projects\RegisterProjectOutcome;
use App\Models\Audit\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function projectsFixturePath(string $name): string
{
    return dirname(__DIR__, 3).'/Fixtures/discovery/'.$name;
}

it('registers a valid Laravel project', function () {
    $path = projectsFixturePath('laravel-blade');

    $result = (new RegisterProject)->register($path, 'My Project');

    expect($result->succeeded())->toBeTrue()
        ->and($result->outcome)->toBe(RegisterProjectOutcome::Created)
        ->and($result->project)->not->toBeNull()
        ->and($result->project->name)->toBe('My Project')
        ->and($result->project->path)->toBe(realpath($path))
        ->and($result->project->public_id)->not->toBeEmpty();

    expect(Project::query()->count())->toBe(1);
});

it('defaults the project name to the directory basename when none is given', function () {
    $path = projectsFixturePath('laravel-blade');

    $result = (new RegisterProject)->register($path);

    expect($result->project->name)->toBe('laravel-blade');
});

it('is idempotent: registering the same resolved path twice returns the existing project, never a duplicate', function () {
    $path = projectsFixturePath('laravel-blade');
    $register = new RegisterProject;

    $first = $register->register($path, 'First Name');
    $second = $register->register($path, 'Second Name');

    expect($first->outcome)->toBe(RegisterProjectOutcome::Created)
        ->and($second->outcome)->toBe(RegisterProjectOutcome::AlreadyRegistered)
        ->and($second->succeeded())->toBeTrue()
        ->and($second->project->id)->toBe($first->project->id)
        // The second call's --name is NOT applied to an existing project —
        // registration is idempotent identity, not an update operation.
        ->and($second->project->name)->toBe('First Name');

    expect(Project::query()->count())->toBe(1);
});

it('recognizes a symlink to an already-registered path as the same project (realpath-normalized)', function () {
    $realPath = projectsFixturePath('laravel-blade');
    $symlinkPath = sys_get_temp_dir().'/laradogs-project-symlink-'.uniqid();
    symlink($realPath, $symlinkPath);

    try {
        $register = new RegisterProject;

        $viaRealPath = $register->register($realPath);
        $viaSymlink = $register->register($symlinkPath);

        expect($viaSymlink->outcome)->toBe(RegisterProjectOutcome::AlreadyRegistered)
            ->and($viaSymlink->project->id)->toBe($viaRealPath->project->id)
            ->and($viaSymlink->project->path)->toBe(realpath($realPath));

        expect(Project::query()->count())->toBe(1);
    } finally {
        unlink($symlinkPath);
    }
});

it('fails cleanly, with no project created, when the path does not exist', function () {
    $result = (new RegisterProject)->register('/nonexistent/path/'.uniqid());

    expect($result->succeeded())->toBeFalse()
        ->and($result->outcome)->toBe(RegisterProjectOutcome::PathInvalid)
        ->and($result->project)->toBeNull()
        ->and($result->discoveryFailure)->not->toBeNull()
        ->and($result->discoveryFailure->status)->toBe(DiscoveryStatus::PathNotFound);

    expect(Project::query()->count())->toBe(0);
});

it('fails cleanly when the path is a file, not a directory', function () {
    $file = projectsFixturePath('laravel-blade').'/composer.json';

    $result = (new RegisterProject)->register($file);

    expect($result->succeeded())->toBeFalse()
        ->and($result->outcome)->toBe(RegisterProjectOutcome::PathInvalid)
        ->and($result->discoveryFailure->status)->toBe(DiscoveryStatus::PathNotDirectory);

    expect(Project::query()->count())->toBe(0);
});

it('registers two genuinely distinct projects as two separate rows', function () {
    $register = new RegisterProject;

    $first = $register->register(projectsFixturePath('laravel-blade'));
    $second = $register->register(projectsFixturePath('laravel-api'));

    expect($first->project->id)->not->toBe($second->project->id);
    expect(Project::query()->count())->toBe(2);
});
