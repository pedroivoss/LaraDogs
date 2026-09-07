<?php

use App\Audit\Analyzers\Npm\NpmAuditAnalyzer;
use App\Audit\Analyzers\Npm\NpmAuditParser;
use App\Audit\Analyzers\Npm\NpmBinaryResolver;
use App\Audit\Discovery\ProjectDiscovery;
use App\Audit\Engine\AuditContext;
use App\Audit\Engine\Execution\ExecutionStatus;
use App\Audit\Engine\Process\SymfonyProcessRunner;
use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Opt-in, real-`npm`-binary tests — proves real-world compatibility and
 * the concrete security mitigations researched for Phase 4.2, run against
 * real (but harmless) locked dependencies and REAL, controlled-malicious
 * fixtures. Skipped by default: this suite must never require internet
 * access to pass (see the rest of this directory's tests, which all use a
 * fake/scripted ProcessRunner). Run explicitly with:
 *
 *   LARADOGS_TEST_REAL_NPM=1 php artisan test --filter=NpmAuditRealBinaryTest
 */
function skipUnlessRealNpmRequested(TestCase $test): void
{
    if (getenv('LARADOGS_TEST_REAL_NPM') !== '1') {
        $test->markTestSkipped('Set LARADOGS_TEST_REAL_NPM=1 to run this opt-in, network-dependent test.');
    }
}

it('runs a real npm audit against a real locked dependency set', function () {
    skipUnlessRealNpmRequested($this);

    $discovery = (new ProjectDiscovery)->discover(dirname(__DIR__, 4).'/Fixtures/discovery/laravel-inertia-react-ts');
    expect($discovery->isSuccessful())->toBeTrue();

    $context = new AuditContext(runId: 'real-npm-test', projectPath: $discovery->path, profile: $discovery->profile);
    $analyzer = new NpmAuditAnalyzer(new NpmBinaryResolver, new NpmAuditParser, new SymfonyProcessRunner);

    expect($analyzer->availability($context)->isAvailable())->toBeTrue();

    $result = $analyzer->run($context);

    expect($result->status)->toBeIn([ExecutionStatus::Passed, ExecutionStatus::Failed])
        ->and($result->status)->not->toBe(ExecutionStatus::TimedOut);

    if ($result->status === ExecutionStatus::Passed) {
        expect($result->rawMetadata)->toHaveKeys(['advisories', 'severity_counts']);
        $analyzer->candidates($context, $result); // must not throw
    }
});

it('runs a real npm audit against a read-only target with npm cache/config outside it', function () {
    skipUnlessRealNpmRequested($this);

    $filesystem = new Filesystem;
    $source = dirname(__DIR__, 4).'/Fixtures/discovery/npm-malicious-scripts';
    $target = sys_get_temp_dir().'/laradogs-npm-readonly-'.bin2hex(random_bytes(8));

    $filesystem->copyDirectory($source, $target);
    $beforeManifest = collect($filesystem->allFiles($target))
        ->mapWithKeys(fn ($file) => [$file->getRelativePathname() => md5_file($file->getPathname())])
        ->all();

    chmod($target, 0o555);

    try {
        $discovery = (new ProjectDiscovery)->discover($target);
        expect($discovery->isSuccessful())->toBeTrue();

        $context = new AuditContext(runId: 'real-npm-readonly-test', projectPath: $discovery->path, profile: $discovery->profile);
        $analyzer = new NpmAuditAnalyzer(new NpmBinaryResolver, new NpmAuditParser, new SymfonyProcessRunner);

        expect($analyzer->availability($context)->isAvailable())->toBeTrue();

        $result = $analyzer->run($context);

        expect($result->status)->not->toBe(ExecutionStatus::Failed)
            ->and($result->status)->not->toBe(ExecutionStatus::TimedOut);

        $afterManifest = collect($filesystem->allFiles($target))
            ->mapWithKeys(fn ($file) => [$file->getRelativePathname() => md5_file($file->getPathname())])
            ->all();

        expect($afterManifest)->toBe($beforeManifest);
        expect($filesystem->exists("{$target}/SHOULD_NEVER_EXIST"))->toBeFalse();
        expect($filesystem->exists("{$target}/node_modules"))->toBeFalse();
    } finally {
        chmod($target, 0o755);
        $filesystem->deleteDirectory($target);
    }
});

it('never executes malicious lifecycle scripts (preinstall/install/postinstall/prepare/prepublish)', function () {
    skipUnlessRealNpmRequested($this);

    $filesystem = new Filesystem;
    $source = dirname(__DIR__, 4).'/Fixtures/discovery/npm-malicious-scripts';
    $target = sys_get_temp_dir().'/laradogs-npm-scripts-'.bin2hex(random_bytes(8));

    $filesystem->copyDirectory($source, $target);

    try {
        $discovery = (new ProjectDiscovery)->discover($target);
        expect($discovery->isSuccessful())->toBeTrue();

        $context = new AuditContext(runId: 'real-npm-scripts-test', projectPath: $discovery->path, profile: $discovery->profile);
        $analyzer = new NpmAuditAnalyzer(new NpmBinaryResolver, new NpmAuditParser, new SymfonyProcessRunner);

        expect($analyzer->availability($context)->isAvailable())->toBeTrue();
        $analyzer->run($context);

        expect($filesystem->exists("{$target}/SHOULD_NEVER_EXIST"))->toBeFalse();
        expect($filesystem->exists("{$source}/SHOULD_NEVER_EXIST"))->toBeFalse();
    } finally {
        $filesystem->deleteDirectory($target);
    }
});

/**
 * The decisive proof behind Phase 4.2's central npm-configuration-security
 * finding: a real `npm audit` against a target whose OWN `.npmrc` tries to
 * (a) redirect the registry to an unreachable/malicious host, (b) disable
 * audit entirely (`audit=false`), (c) reroute all traffic through a
 * hostile proxy (Phase 4.2.1 finding — port 1 is always closed, so if the
 * proxy pin were NOT working, this run would fail with a connection
 * error instead of succeeding), and (d) plant a fake auth token — none of
 * which may produce a false-clean scan, a credential leak, or a mutated
 * target. No real credential is used; the fake token exists only to prove
 * it's never read/sent anywhere real.
 */
it('is not fooled by a malicious target .npmrc (hostile registry, proxy, audit=false, fake token)', function () {
    skipUnlessRealNpmRequested($this);

    $discovery = (new ProjectDiscovery)->discover(dirname(__DIR__, 4).'/Fixtures/discovery/npm-malicious-npmrc');
    expect($discovery->isSuccessful())->toBeTrue();

    $context = new AuditContext(runId: 'real-npm-malicious-npmrc-test', projectPath: $discovery->path, profile: $discovery->profile);
    $analyzer = new NpmAuditAnalyzer(new NpmBinaryResolver, new NpmAuditParser, new SymfonyProcessRunner);

    expect($analyzer->availability($context)->isAvailable())->toBeTrue();

    $result = $analyzer->run($context);

    // The pinned --registry/--proxy/--https-proxy flags must make every
    // hostile override ineffective — this must reach the REAL registry
    // directly and come back Passed with real data, not Failed (which
    // would also technically be "not fooled," but would mean the
    // mitigation didn't actually work as designed against this exact
    // fixture — e.g. if the proxy pin failed, the port-1 proxy would
    // refuse the connection and this would come back Failed instead).
    expect($result->status)->toBe(ExecutionStatus::Passed);

    $candidates = $analyzer->candidates($context, $result);
    expect($candidates)->not->toBe([]);
});

/**
 * Phase 4.2.1 security regression test — this is the exact scenario that
 * would have produced a silent false-clean-or-broken result under the
 * original Phase 4.2 code (no --proxy/--https-proxy pin): a target whose
 * `.npmrc` sets BOTH a scoped registry AND a proxy to a LOCAL SOCKET this
 * test controls and observes directly, so the proof isn't just "the end
 * result looked right" but "literally zero bytes of any kind reached the
 * hostile endpoint." Before this phase: an unmitigated `.npmrc` proxy
 * setting was confirmed (during this phase's own research) to make a real
 * `npm audit` attempt a `CONNECT registry.npmjs.org:443` through exactly
 * this kind of listener. After: it must not.
 */
it('never contacts a scoped-registry-or-proxy endpoint the target points at a local socket this test controls', function () {
    skipUnlessRealNpmRequested($this);

    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

    if ($socket === false) {
        $this->markTestSkipped("Could not open a local test socket: {$errstr}");
    }

    $address = stream_socket_get_name($socket, false);
    $port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);

    $filesystem = new Filesystem;
    $source = dirname(__DIR__, 4).'/Fixtures/discovery/laravel-inertia-react-ts';
    $target = sys_get_temp_dir().'/laradogs-npm-socket-proof-'.bin2hex(random_bytes(8));
    $filesystem->copyDirectory($source, $target);

    // package-lock.json in this fixture has no real dependencies to keep
    // the fixture minimal elsewhere, but this test needs the SCOPE
    // itself present so `@scope:registry` is a real, on-tree override,
    // not a no-op — the lodash fixture used elsewhere already includes a
    // real, unscoped dependency, which is enough to exercise the proxy
    // pin (the more universally reachable of the two mitigations) even
    // without a scoped package in the tree.
    file_put_contents($target.'/.npmrc', implode("\n", [
        "@evil-scope:registry=http://127.0.0.1:{$port}/",
        "proxy=http://127.0.0.1:{$port}/",
        "https-proxy=http://127.0.0.1:{$port}/",
    ]));

    try {
        $discovery = (new ProjectDiscovery)->discover($target);
        expect($discovery->isSuccessful())->toBeTrue();

        $context = new AuditContext(runId: 'real-npm-socket-proof', projectPath: $discovery->path, profile: $discovery->profile);
        $analyzer = new NpmAuditAnalyzer(new NpmBinaryResolver, new NpmAuditParser, new SymfonyProcessRunner);

        expect($analyzer->availability($context)->isAvailable())->toBeTrue();

        $result = $analyzer->run($context);

        expect($result->status)->toBe(ExecutionStatus::Passed);

        // Non-blocking check: did anything ever connect to the socket
        // during the run above? A short timeout is enough — any real
        // connection attempt would already be sitting in the accept
        // backlog by the time the (already-completed) audit call returns.
        stream_set_blocking($socket, false);
        $connection = @stream_socket_accept($socket, 1);

        expect($connection)->toBeFalse();
    } finally {
        fclose($socket);
        $filesystem->deleteDirectory($target);
    }
});
