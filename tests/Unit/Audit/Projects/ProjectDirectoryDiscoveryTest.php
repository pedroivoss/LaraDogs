<?php

use App\Audit\Projects\ProjectDirectoryDiscovery;
use App\Models\Audit\Project;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeProjectRootFixture(): string
{
    $root = sys_get_temp_dir().'/laradogs-project-root-'.uniqid();
    mkdir($root, recursive: true);

    return $root;
}

afterEach(function () {
    config(['laradogs.projects.root' => '/projects']);
});

it('lists direct child directories of the configured root', function () {
    $root = makeProjectRootFixture();
    mkdir($root.'/Alpha');
    mkdir($root.'/Beta');
    file_put_contents($root.'/not-a-directory.txt', 'x');
    config(['laradogs.projects.root' => $root]);

    $result = (new ProjectDirectoryDiscovery)->list();

    expect($result->rootAvailable)->toBeTrue();
    $names = array_map(fn ($c) => $c->name, $result->candidates);
    expect($names)->toBe(['Alpha', 'Beta']);

    (new Filesystem)->deleteDirectory($root);
});

it('marks a candidate as already registered when its resolved path matches an existing Project', function () {
    $root = makeProjectRootFixture();
    mkdir($root.'/Registered');
    config(['laradogs.projects.root' => $root]);

    Project::query()->create(['name' => 'Registered', 'path' => realpath($root.'/Registered')]);

    $result = (new ProjectDirectoryDiscovery)->list();

    expect($result->candidates[0]->alreadyRegistered)->toBeTrue();

    (new Filesystem)->deleteDirectory($root);
});

it('reports the root as unavailable when it does not exist', function () {
    config(['laradogs.projects.root' => '/this/path/does/not/exist']);

    $result = (new ProjectDirectoryDiscovery)->list();

    expect($result->rootAvailable)->toBeFalse()
        ->and($result->candidates)->toBe([]);
});

it('reports an empty list for a root that exists but has no entries', function () {
    $root = makeProjectRootFixture();
    config(['laradogs.projects.root' => $root]);

    $result = (new ProjectDirectoryDiscovery)->list();

    expect($result->rootAvailable)->toBeTrue()
        ->and($result->candidates)->toBe([]);

    (new Filesystem)->deleteDirectory($root);
});

it('rejects a symlink under the root that escapes it', function () {
    $root = makeProjectRootFixture();
    $outside = sys_get_temp_dir().'/laradogs-outside-'.uniqid();
    mkdir($outside, recursive: true);
    symlink($outside, $root.'/escape');
    config(['laradogs.projects.root' => $root]);

    $result = (new ProjectDirectoryDiscovery)->list();

    expect($result->candidates)->toBe([]);
    expect((new ProjectDirectoryDiscovery)->resolve('escape'))->toBeNull();

    (new Filesystem)->deleteDirectory($outside);
    (new Filesystem)->deleteDirectory($root);
});

it('resolve() rejects path traversal and absolute-path input, never treating them as names', function () {
    $root = makeProjectRootFixture();
    mkdir($root.'/Real');
    config(['laradogs.projects.root' => $root]);

    $discovery = new ProjectDirectoryDiscovery;

    expect($discovery->resolve('../etc'))->toBeNull();
    expect($discovery->resolve('/etc/passwd'))->toBeNull();
    expect($discovery->resolve('..'))->toBeNull();
    expect($discovery->resolve('.'))->toBeNull();
    expect($discovery->resolve(''))->toBeNull();
    expect($discovery->resolve('does-not-exist'))->toBeNull();
    expect($discovery->resolve('Real'))->toBe(realpath($root.'/Real'));

    (new Filesystem)->deleteDirectory($root);
});
