<?php

use App\Audit\Discovery\ProjectDiscovery;
use Illuminate\Filesystem\Filesystem;

/**
 * Proves the core security property of Project Discovery: composer.json's
 * `scripts` and package.json's `scripts` are read as inert data only.
 * Neither Composer nor npm/node is ever invoked against the analyzed
 * project, so a malicious `post-install-cmd`/`postinstall` script must
 * never run.
 *
 * The fixture is copied to a throwaway temp directory first (rather than
 * discovering the committed fixture in place) so that even a regression in
 * this guarantee cannot write a stray file into the repository itself —
 * only the temp copy could ever be polluted, and it's deleted either way.
 */
it('never executes composer.json or package.json scripts found in the analyzed project', function () {
    $filesystem = new Filesystem;

    $source = dirname(__DIR__, 3).'/Fixtures/discovery/no-code-execution';
    $target = sys_get_temp_dir().'/laradogs-discovery-no-exec-'.uniqid();

    $filesystem->copyDirectory($source, $target);

    try {
        $result = (new ProjectDiscovery)->discover($target);

        expect($result->isSuccessful())->toBeTrue();
        expect($filesystem->exists($target.'/SHOULD_NEVER_EXIST'))->toBeFalse();
        expect($filesystem->exists($source.'/SHOULD_NEVER_EXIST'))->toBeFalse();
    } finally {
        $filesystem->deleteDirectory($target);
    }
});
