<?php

use App\Audit\Analyzers\Npm\NpmConfigInspector;
use App\Audit\Analyzers\Npm\NpmConfigTooLargeException;
use Illuminate\Filesystem\Filesystem;

function withTempNpmrc(?string $contents, Closure $callback): mixed
{
    $dir = sys_get_temp_dir().'/laradogs-npmrc-inspector-'.bin2hex(random_bytes(8));
    mkdir($dir);

    if ($contents !== null) {
        file_put_contents($dir.'/.npmrc', $contents);
    }

    try {
        return $callback($dir);
    } finally {
        (new Filesystem)->deleteDirectory($dir);
    }
}

it('returns an empty list when the project has no .npmrc at all', function () {
    withTempNpmrc(null, function (string $dir) {
        expect((new NpmConfigInspector)->inspect($dir))->toBe([]);
    });
});

it('does not flag config keys that are already neutralized elsewhere (registry, scoped registry, audit, proxy, cache, userconfig, auth)', function () {
    $contents = implode("\n", [
        'registry=https://this-registry-does-not-resolve-laradogs-test.invalid',
        '@evil:registry=https://this-registry-does-not-resolve-laradogs-test.invalid',
        'audit=false',
        'proxy=http://127.0.0.1:1/',
        'https-proxy=http://127.0.0.1:1/',
        'cache=/some/path',
        'userconfig=/some/path',
        'globalconfig=/some/path',
        '//registry.npmjs.org/:_authToken=FAKE_TOKEN',
        'username=fake',
        '_password=ZmFrZQ==',
    ]);

    withTempNpmrc($contents, function (string $dir) {
        expect((new NpmConfigInspector)->inspect($dir))->toBe([]);
    });
});

it('flags cafile in plain form', function () {
    withTempNpmrc("cafile=/etc/passwd\n", function (string $dir) {
        expect((new NpmConfigInspector)->inspect($dir))->toBe(['cafile']);
    });
});

it('flags cert/certfile/key/keyfile, deduplicated and sorted for a stable assertion', function () {
    $contents = implode("\n", [
        'cert=-----BEGIN CERTIFICATE-----',
        'certfile=/some/cert.pem',
        'key=-----BEGIN KEY-----',
        'keyfile=/some/key.pem',
    ]);

    withTempNpmrc($contents, function (string $dir) {
        $found = (new NpmConfigInspector)->inspect($dir);
        sort($found);
        expect($found)->toBe(['cert', 'certfile', 'key', 'keyfile']);
    });
});

it('flags a registry-scoped keyfile (//host/:keyfile=...) the same as the plain form', function () {
    withTempNpmrc("//other-registry.tld/:keyfile=/path/to/key.pem\n", function (string $dir) {
        expect((new NpmConfigInspector)->inspect($dir))->toBe(['keyfile']);
    });
});

it('is case-insensitive and never returns the matched value', function () {
    withTempNpmrc("CAFILE=/some/very/secret/path\n", function (string $dir) {
        $found = (new NpmConfigInspector)->inspect($dir);
        expect($found)->toBe(['cafile']);

        foreach ($found as $key) {
            expect($key)->not->toContain('/some/very/secret/path');
        }
    });
});

it('ignores comments and blank lines', function () {
    $contents = implode("\n", [
        '; a comment',
        '# another comment',
        '',
        'registry=https://registry.npmjs.org',
    ]);

    withTempNpmrc($contents, function (string $dir) {
        expect((new NpmConfigInspector)->inspect($dir))->toBe([]);
    });
});

it('deduplicates repeated dangerous keys', function () {
    $contents = "cafile=/a\ncafile=/b\n";

    withTempNpmrc($contents, function (string $dir) {
        expect((new NpmConfigInspector)->inspect($dir))->toBe(['cafile']);
    });
});

it('throws NpmConfigTooLargeException for a .npmrc exceeding the size cap, rather than partially scanning it', function () {
    $huge = str_repeat("registry=https://registry.npmjs.org\n", 5000).'cafile=/etc/passwd'."\n";
    expect(strlen($huge))->toBeGreaterThan(NpmConfigInspector::MAX_BYTES);

    withTempNpmrc($huge, function (string $dir) {
        (new NpmConfigInspector)->inspect($dir);
    });
})->throws(NpmConfigTooLargeException::class);
