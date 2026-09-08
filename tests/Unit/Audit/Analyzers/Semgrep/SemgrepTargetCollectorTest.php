<?php

use App\Audit\Analyzers\Semgrep\SemgrepTargetCollector;
use Illuminate\Filesystem\Filesystem;

function withTempProjectTree(Closure $build, Closure $callback): mixed
{
    $filesystem = new Filesystem;
    $root = sys_get_temp_dir().'/laradogs-semgrep-collector-'.bin2hex(random_bytes(8));
    $filesystem->makeDirectory($root, recursive: true);

    try {
        $build($root, $filesystem);

        return $callback(realpath($root));
    } finally {
        $filesystem->deleteDirectory($root);
    }
}

it('collects .php files under the project root', function () {
    withTempProjectTree(function (string $root, Filesystem $fs) {
        $fs->makeDirectory("$root/app", recursive: true);
        file_put_contents("$root/app/One.php", '<?php');
        file_put_contents("$root/app/Two.php", '<?php');
        file_put_contents("$root/app/notes.txt", 'irrelevant');
    }, function (string $root) {
        $files = (new SemgrepTargetCollector)->collect($root);

        expect($files)->toHaveCount(2);
        foreach ($files as $file) {
            expect($file)->toEndWith('.php');
        }
    });
});

it('excludes vendor, node_modules, storage, bootstrap/cache, public/build, dist, coverage and .git', function () {
    withTempProjectTree(function (string $root, Filesystem $fs) {
        $excludedDirs = ['vendor', 'node_modules', 'storage', 'bootstrap/cache', 'public/build', 'dist', 'coverage', '.git'];

        foreach ($excludedDirs as $dir) {
            $fs->makeDirectory("$root/$dir", recursive: true);
            file_put_contents("$root/$dir/Excluded.php", '<?php eval("should never be scanned");');
        }

        $fs->makeDirectory("$root/app", recursive: true);
        file_put_contents("$root/app/Included.php", '<?php');
    }, function (string $root) {
        $files = (new SemgrepTargetCollector)->collect($root);

        expect($files)->toHaveCount(1)
            ->and($files[0])->toEndWith('Included.php');
    });
});

it('never returns a symlink, even one pointing inside the project root', function () {
    withTempProjectTree(function (string $root, Filesystem $fs) {
        $fs->makeDirectory("$root/app", recursive: true);
        file_put_contents("$root/app/Real.php", '<?php');
        symlink("$root/app/Real.php", "$root/app/Link.php");
    }, function (string $root) {
        $files = (new SemgrepTargetCollector)->collect($root);

        expect($files)->toHaveCount(1)
            ->and($files[0])->toEndWith('Real.php');
    });
});

it('never returns a path escaping the project root through a symlinked directory', function () {
    withTempProjectTree(function (string $root, Filesystem $fs) {
        $outside = dirname($root).'/laradogs-semgrep-outside-'.bin2hex(random_bytes(8));
        $fs->makeDirectory($outside, recursive: true);
        file_put_contents("$outside/Secret.php", '<?php eval("outside the root");');

        $fs->makeDirectory("$root/app", recursive: true);
        symlink($outside, "$root/app/escaped");

        // Clean up the sibling directory ourselves — it's outside $root so
        // withTempProjectTree's own teardown won't reach it.
        register_shutdown_function(fn () => $fs->deleteDirectory($outside));
    }, function (string $root) {
        $files = (new SemgrepTargetCollector)->collect($root);

        expect($files)->toBe([]);
    });
});

it('returns an empty list for a root with no matching files', function () {
    withTempProjectTree(function (string $root, Filesystem $fs) {
        $fs->makeDirectory("$root/app", recursive: true);
        file_put_contents("$root/app/readme.md", 'irrelevant');
    }, function (string $root) {
        expect((new SemgrepTargetCollector)->collect($root))->toBe([]);
    });
});

it('returns an empty list for a root that does not exist', function () {
    expect((new SemgrepTargetCollector)->collect(sys_get_temp_dir().'/laradogs-does-not-exist-'.bin2hex(random_bytes(8))))->toBe([]);
});
