<?php

use App\Audit\Discovery\Filesystem\ProjectFilesystem;
use Illuminate\Filesystem\Filesystem;

function makeTempProjectRoot(): string
{
    $root = sys_get_temp_dir().'/laradogs-fs-test-'.uniqid();
    mkdir($root, recursive: true);

    return $root;
}

it('rejects path traversal outside the project root', function () {
    $root = makeTempProjectRoot();
    file_put_contents($root.'/inside.txt', 'ok');

    $fs = new ProjectFilesystem($root);

    expect($fs->resolve('../outside.txt'))->toBeNull();
    expect($fs->resolve('../../etc/passwd'))->toBeNull();
    expect($fs->fileExists('../../etc/passwd'))->toBeFalse();
    expect($fs->fileExists('inside.txt'))->toBeTrue();

    (new Filesystem)->deleteDirectory($root);
});

it('rejects a symlink that escapes the project root', function () {
    $root = makeTempProjectRoot();
    $outsideSecret = sys_get_temp_dir().'/laradogs-fs-outside-'.uniqid().'.txt';
    file_put_contents($outsideSecret, 'super-secret');
    symlink($outsideSecret, $root.'/escape.txt');

    $fs = new ProjectFilesystem($root);

    expect($fs->fileExists('escape.txt'))->toBeFalse();
    expect($fs->readFile('escape.txt'))->toBeNull();

    unlink($outsideSecret);
    (new Filesystem)->deleteDirectory($root);
});

it('treats a file larger than the configured cap as unreadable', function () {
    $root = makeTempProjectRoot();
    file_put_contents($root.'/big.txt', str_repeat('a', 100));

    $fs = new ProjectFilesystem($root, maxReadableBytes: 10);

    expect($fs->readFile('big.txt'))->toBeNull();

    (new Filesystem)->deleteDirectory($root);
});

it('finds a blade file within a bounded recursive scan', function () {
    $root = makeTempProjectRoot();
    mkdir($root.'/resources/views/nested', recursive: true);
    file_put_contents($root.'/resources/views/nested/page.blade.php', '<div></div>');

    $fs = new ProjectFilesystem($root);

    expect($fs->hasFileWithSuffixUnder('resources/views', '.blade.php'))->toBeTrue();

    (new Filesystem)->deleteDirectory($root);
});

it('does not find a blade file that only exists through a symlinked directory escaping the root', function () {
    $root = makeTempProjectRoot();
    $outsideDir = sys_get_temp_dir().'/laradogs-fs-outside-dir-'.uniqid();
    mkdir($outsideDir, recursive: true);
    file_put_contents($outsideDir.'/secret.blade.php', '<div></div>');
    mkdir($root.'/resources', recursive: true);
    symlink($outsideDir, $root.'/resources/views');

    $fs = new ProjectFilesystem($root);

    expect($fs->hasFileWithSuffixUnder('resources/views', '.blade.php'))->toBeFalse();

    (new Filesystem)->deleteDirectory($outsideDir);
    (new Filesystem)->deleteDirectory($root);
});
