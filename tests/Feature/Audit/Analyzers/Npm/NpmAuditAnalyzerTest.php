<?php

use App\Audit\Analyzers\Npm\NpmAuditAnalyzer;
use App\Audit\Analyzers\Npm\NpmAuditParser;
use App\Audit\Analyzers\Npm\NpmBinaryResolver;
use App\Audit\Discovery\ProjectDiscovery;
use App\Audit\Engine\AuditContext;
use App\Audit\Engine\Execution\CoverageMode;
use App\Audit\Engine\Execution\ExecutionStatus;
use App\Audit\Engine\Process\ProcessResult;
use App\Audit\Findings\Severity;
use Tests\Support\Process\FakeProcessRunner;
use Tests\TestCase;

uses(TestCase::class);

function npmAuditFixtureJson(string $name): string
{
    return file_get_contents(dirname(__DIR__, 4).'/Fixtures/npm-audit/'.$name);
}

function npmDiscoveryContext(string $fixture): AuditContext
{
    $discovery = (new ProjectDiscovery)->discover(dirname(__DIR__, 4).'/Fixtures/discovery/'.$fixture);
    expect($discovery->isSuccessful())->toBeTrue();

    return new AuditContext(runId: 'test-run', projectPath: $discovery->path, profile: $discovery->profile);
}

function makeNpmAuditAnalyzer(FakeProcessRunner $runner): NpmAuditAnalyzer
{
    // A real, executable file so NpmBinaryResolver's is_file()/
    // is_executable() checks succeed — its actual content is irrelevant
    // since ProcessRunner itself is faked.
    config(['laradogs.npm.binary' => PHP_BINARY]);

    return new NpmAuditAnalyzer(new NpmBinaryResolver, new NpmAuditParser, $runner);
}

function npmVersionCheckResult(string $version = '10.9.7'): ProcessResult
{
    return new ProcessResult(
        exitCode: 0,
        stdout: $version,
        stderr: '',
        timedOut: false,
        outputTruncated: false,
        durationMs: 5,
    );
}

// --- Applicability -----------------------------------------------------

it('is applicable to an npm project with a package-lock.json', function () {
    $analyzer = makeNpmAuditAnalyzer(new FakeProcessRunner);
    $context = npmDiscoveryContext('laravel-inertia-react-ts');

    expect($analyzer->applicability($context->profile)->isApplicable())->toBeTrue();
});

it('is applicable to an npm project with an npm-shrinkwrap.json', function () {
    $analyzer = makeNpmAuditAnalyzer(new FakeProcessRunner);
    $context = npmDiscoveryContext('npm-shrinkwrap-only');

    expect($analyzer->applicability($context->profile)->isApplicable())->toBeTrue();
});

it('is not applicable to package.json without any npm-native lockfile, and never suggests installing one', function () {
    $analyzer = makeNpmAuditAnalyzer(new FakeProcessRunner);
    $context = npmDiscoveryContext('package-json-without-lock');

    $applicability = $analyzer->applicability($context->profile);

    expect($applicability->isApplicable())->toBeFalse()
        ->and($applicability->reason)->toContain('lockfile');
});

it('is not applicable to a yarn-only project — yarn.lock is not an npm lockfile', function () {
    $analyzer = makeNpmAuditAnalyzer(new FakeProcessRunner);
    $context = npmDiscoveryContext('laravel-inertia-vue');

    expect($analyzer->applicability($context->profile)->isApplicable())->toBeFalse();
});

it('is not applicable to a pnpm-only project — pnpm-lock.yaml is not an npm lockfile', function () {
    $analyzer = makeNpmAuditAnalyzer(new FakeProcessRunner);
    $context = npmDiscoveryContext('pnpm-only');

    expect($analyzer->applicability($context->profile)->isApplicable())->toBeFalse();
});

it('is not applicable to a non-npm (pure Composer) project', function () {
    $analyzer = makeNpmAuditAnalyzer(new FakeProcessRunner);
    $context = npmDiscoveryContext('laravel-with-composer-lock');

    expect($analyzer->applicability($context->profile)->isApplicable())->toBeFalse();
});

// --- Availability --------------------------------------------------------

it('is unavailable when the npm binary cannot be resolved', function () {
    config(['laradogs.npm.binary' => '/no/such/binary/here']);
    $analyzer = new NpmAuditAnalyzer(new NpmBinaryResolver, new NpmAuditParser, new FakeProcessRunner);
    $context = npmDiscoveryContext('laravel-inertia-react-ts');

    expect($analyzer->availability($context)->isAvailable())->toBeFalse();
});

it('is unavailable when the version check process fails', function () {
    $runner = new FakeProcessRunner(new ProcessResult(1, '', 'boom', false, false, 5));
    $analyzer = makeNpmAuditAnalyzer($runner);
    $context = npmDiscoveryContext('laravel-inertia-react-ts');

    expect($analyzer->availability($context)->isAvailable())->toBeFalse();
});

it('is unavailable when the installed npm version is below the minimum supported', function () {
    $runner = new FakeProcessRunner(npmVersionCheckResult('6.14.18'));
    $analyzer = makeNpmAuditAnalyzer($runner);
    $context = npmDiscoveryContext('laravel-inertia-react-ts');

    $availability = $analyzer->availability($context);

    expect($availability->isAvailable())->toBeFalse()
        ->and($availability->reason)->toContain('6.14.18');
});

it('is available when a supported npm version is detected', function () {
    $runner = new FakeProcessRunner(npmVersionCheckResult('10.9.7'));
    $analyzer = makeNpmAuditAnalyzer($runner);
    $context = npmDiscoveryContext('laravel-inertia-react-ts');

    expect($analyzer->availability($context)->isAvailable())->toBeTrue();
});

// --- run(): safety of the invoked command ---------------------------------

it('invokes npm audit with --package-lock-only --ignore-scripts and a pinned --registry, never mutating the target', function () {
    $runner = new FakeProcessRunner(
        npmVersionCheckResult(),
        new ProcessResult(0, npmAuditFixtureJson('clean.json'), '', false, false, 10),
    );
    $analyzer = makeNpmAuditAnalyzer($runner);
    $context = npmDiscoveryContext('laravel-inertia-react-ts');

    expect($analyzer->availability($context)->isAvailable())->toBeTrue();
    $analyzer->run($context);

    $auditCall = $runner->calls()[1];

    expect($auditCall->argv)->toContain('audit')
        ->and($auditCall->argv)->toContain('--json')
        ->and($auditCall->argv)->toContain('--package-lock-only')
        ->and($auditCall->argv)->toContain('--ignore-scripts')
        ->and($auditCall->workingDirectory)->toBe($context->projectPath);

    $registryArg = collect($auditCall->argv)->first(fn ($arg) => str_starts_with($arg, '--registry='));
    expect($registryArg)->not->toBeNull()
        ->and($registryArg)->toBe('--registry=https://registry.npmjs.org');
});

it('always forces NPM_CONFIG_USERCONFIG and NPM_CONFIG_CACHE to LaraDogs-controlled paths', function () {
    $runner = new FakeProcessRunner(
        npmVersionCheckResult(),
        new ProcessResult(0, npmAuditFixtureJson('clean.json'), '', false, false, 10),
    );
    $analyzer = makeNpmAuditAnalyzer($runner);
    $context = npmDiscoveryContext('laravel-inertia-react-ts');

    $analyzer->availability($context);
    $analyzer->run($context);

    foreach ($runner->calls() as $call) {
        expect($call->environment)->toHaveKey('NPM_CONFIG_USERCONFIG')
            ->and($call->environment)->toHaveKey('NPM_CONFIG_CACHE')
            ->and($call->environment['NPM_CONFIG_USERCONFIG'])->toBe((string) config('laradogs.npm.userconfig_path'))
            ->and($call->environment['NPM_CONFIG_CACHE'])->toBe((string) config('laradogs.npm.cache_path'));
    }
});

// --- run(): outcome handling ---------------------------------------------

it('passes with zero candidates on a clean audit', function () {
    $runner = new FakeProcessRunner(new ProcessResult(0, npmAuditFixtureJson('clean.json'), '', false, false, 10));
    $analyzer = makeNpmAuditAnalyzer($runner);
    $context = npmDiscoveryContext('laravel-inertia-react-ts');

    $result = $analyzer->run($context);

    expect($result->status)->toBe(ExecutionStatus::Passed)
        ->and($analyzer->candidates($context, $result))->toBe([]);
});

it('passes with exit code 1 when vulnerabilities are found — a non-zero exit is not itself a failure', function () {
    $runner = new FakeProcessRunner(new ProcessResult(1, npmAuditFixtureJson('with-direct-vulnerability.json'), '', false, false, 10));
    $analyzer = makeNpmAuditAnalyzer($runner);
    $context = npmDiscoveryContext('laravel-inertia-react-ts');

    $result = $analyzer->run($context);

    expect($result->status)->toBe(ExecutionStatus::Passed);

    $candidates = $analyzer->candidates($context, $result);
    expect($candidates)->toHaveCount(2);

    $candidate = $candidates[0];
    expect($candidate->ruleId)->toBe('lodash:1106900')
        ->and($candidate->analyzerId)->toBe('npm-audit')
        ->and($candidate->severity)->toBe(Severity::Medium)
        ->and($candidate->confidence->value)->toBe('high')
        ->and($candidate->references)->toBe(['https://github.com/advisories/GHSA-fvqr-27wr-82fm']);
});

it('produces exactly one candidate per real advisory, never one for a pure via cross-reference', function () {
    $runner = new FakeProcessRunner(new ProcessResult(1, npmAuditFixtureJson('with-transitive-vulnerability.json'), '', false, false, 10));
    $analyzer = makeNpmAuditAnalyzer($runner);
    $context = npmDiscoveryContext('laravel-inertia-react-ts');

    $result = $analyzer->run($context);
    $candidates = $analyzer->candidates($context, $result);

    expect($candidates)->toHaveCount(2);

    $ruleIds = array_map(fn ($c) => $c->ruleId, $candidates);
    expect($ruleIds)->toContain('debug:1094457')
        ->and($ruleIds)->toContain('ms:1109573')
        ->and($ruleIds)->not->toContain('mocha:0');

    $debug = collect($candidates)->firstWhere('ruleId', 'debug:1094457');
    expect($debug->metadata['is_direct'])->toBeFalse();
});

it('never invents a severity for an advisory the source did not rate', function () {
    $runner = new FakeProcessRunner(new ProcessResult(0, json_encode([
        'auditReportVersion' => 2,
        'vulnerabilities' => [
            'pkg' => ['via' => [['source' => 1, 'title' => 'No severity']]],
        ],
        'metadata' => ['vulnerabilities' => ['total' => 0]],
    ]), '', false, false, 10));
    $analyzer = makeNpmAuditAnalyzer($runner);
    $context = npmDiscoveryContext('laravel-inertia-react-ts');

    $result = $analyzer->run($context);
    $candidates = $analyzer->candidates($context, $result);

    expect($candidates[0]->severity)->toBe(Severity::Unknown);
});

it('fails on malformed JSON output rather than reporting a false-clean pass', function () {
    $runner = new FakeProcessRunner(new ProcessResult(0, '{not valid json', '', false, false, 10));
    $analyzer = makeNpmAuditAnalyzer($runner);
    $context = npmDiscoveryContext('laravel-inertia-react-ts');

    $result = $analyzer->run($context);

    expect($result->status)->toBe(ExecutionStatus::Failed)
        ->and($analyzer->candidates($context, $result))->toBe([]);
});

it('fails closed on a registry/network error response rather than reporting a false-clean pass', function () {
    $runner = new FakeProcessRunner(new ProcessResult(1, npmAuditFixtureJson('registry-error.json'), '', false, false, 10));
    $analyzer = makeNpmAuditAnalyzer($runner);
    $context = npmDiscoveryContext('laravel-inertia-react-ts');

    $result = $analyzer->run($context);

    expect($result->status)->toBe(ExecutionStatus::Failed)
        ->and($analyzer->candidates($context, $result))->toBe([]);
});

it('fails closed on an unrecognized/older schema shape rather than reporting a false-clean pass', function () {
    $runner = new FakeProcessRunner(new ProcessResult(0, npmAuditFixtureJson('unexpected-schema-v1.json'), '', false, false, 10));
    $analyzer = makeNpmAuditAnalyzer($runner);
    $context = npmDiscoveryContext('laravel-inertia-react-ts');

    expect($analyzer->run($context)->status)->toBe(ExecutionStatus::Failed);
});

it('reports timedOut distinctly, never as a plain failure or a pass', function () {
    $runner = new FakeProcessRunner(new ProcessResult(null, '', '', true, false, 30_000));
    $analyzer = makeNpmAuditAnalyzer($runner);
    $context = npmDiscoveryContext('laravel-inertia-react-ts');

    expect($analyzer->run($context)->status)->toBe(ExecutionStatus::TimedOut);
});

it('fails on truncated output rather than trusting a partial JSON body', function () {
    $runner = new FakeProcessRunner(new ProcessResult(0, npmAuditFixtureJson('clean.json'), '', false, true, 10));
    $analyzer = makeNpmAuditAnalyzer($runner);
    $context = npmDiscoveryContext('laravel-inertia-react-ts');

    expect($analyzer->run($context)->status)->toBe(ExecutionStatus::Failed);
});

it('preserves fixAvailable as metadata without ever executing a fix', function () {
    $runner = new FakeProcessRunner(new ProcessResult(1, npmAuditFixtureJson('with-transitive-vulnerability.json'), '', false, false, 10));
    $analyzer = makeNpmAuditAnalyzer($runner);
    $context = npmDiscoveryContext('laravel-inertia-react-ts');

    $result = $analyzer->run($context);
    $candidates = $analyzer->candidates($context, $result);

    $debug = collect($candidates)->firstWhere('ruleId', 'debug:1094457');
    expect($debug->metadata['fix_available'])->toBe([
        'name' => 'mocha',
        'version' => '12.0.0',
        'is_semver_major' => true,
    ]);

    // Never actually invoked as a subcommand/argument anywhere.
    foreach ($runner->calls() as $call) {
        expect($call->argv)->not->toContain('fix');
    }
});

it('always declares Unknown coverage — npm gives no explicit rule-id universe to declare Explicit/Full from', function () {
    $runner = new FakeProcessRunner(new ProcessResult(1, npmAuditFixtureJson('with-direct-vulnerability.json'), '', false, false, 10));
    $analyzer = makeNpmAuditAnalyzer($runner);
    $context = npmDiscoveryContext('laravel-inertia-react-ts');

    expect($analyzer->run($context)->coverage->mode)->toBe(CoverageMode::Unknown);
});

// --- Phase 4.2.1: npm registry trust hardening --------------------------

it('always forces the proxy off unless an operator explicitly configures a trusted one', function () {
    config(['laradogs.npm.proxy' => null, 'laradogs.npm.https_proxy' => null]);

    $runner = new FakeProcessRunner(
        npmVersionCheckResult(),
        new ProcessResult(0, npmAuditFixtureJson('clean.json'), '', false, false, 10),
    );
    $analyzer = makeNpmAuditAnalyzer($runner);
    $context = npmDiscoveryContext('laravel-inertia-react-ts');

    $analyzer->availability($context);
    $analyzer->run($context);

    $auditCall = $runner->calls()[1];

    expect($auditCall->argv)->toContain('--proxy=false')
        ->and($auditCall->argv)->toContain('--https-proxy=false')
        ->and($auditCall->argv)->toContain('--strict-ssl=true');
});

it('uses an operator-configured trusted proxy instead of forcing it off, when one is explicitly set', function () {
    config([
        'laradogs.npm.proxy' => 'http://trusted-operator-proxy.internal:3128',
        'laradogs.npm.https_proxy' => 'http://trusted-operator-proxy.internal:3128',
    ]);

    $runner = new FakeProcessRunner(
        npmVersionCheckResult(),
        new ProcessResult(0, npmAuditFixtureJson('clean.json'), '', false, false, 10),
    );
    $analyzer = makeNpmAuditAnalyzer($runner);
    $context = npmDiscoveryContext('laravel-inertia-react-ts');

    $analyzer->availability($context);
    $analyzer->run($context);

    $auditCall = $runner->calls()[1];

    expect($auditCall->argv)->toContain('--proxy=http://trusted-operator-proxy.internal:3128')
        ->and($auditCall->argv)->toContain('--https-proxy=http://trusted-operator-proxy.internal:3128');

    config(['laradogs.npm.proxy' => null, 'laradogs.npm.https_proxy' => null]);
});

it('fails closed with UNSAFE_TARGET_NPM_CONFIG when the target .npmrc declares cafile, never invoking npm at all', function () {
    $runner = new FakeProcessRunner(
        npmVersionCheckResult(),
        new ProcessResult(0, npmAuditFixtureJson('clean.json'), '', false, false, 10),
    );
    $analyzer = makeNpmAuditAnalyzer($runner);
    $context = npmDiscoveryContext('npm-unsafe-npmrc');

    expect($analyzer->availability($context)->isAvailable())->toBeTrue();

    $result = $analyzer->run($context);

    expect($result->status)->toBe(ExecutionStatus::Failed)
        ->and($result->summary)->toContain('UNSAFE_TARGET_NPM_CONFIG')
        ->and($analyzer->candidates($context, $result))->toBe([]);

    // The diagnostic names the dangerous key but never the value the
    // fixture's .npmrc actually set (/etc/passwd).
    expect($result->diagnostics[0]->message)->toContain('cafile')
        ->and($result->diagnostics[0]->message)->not->toContain('/etc/passwd');

    // Never even attempted to run `npm audit` — only the (already
    // recorded) --version call from availability() above is present.
    expect($runner->calls())->toHaveCount(1);
});

it('produces no FindingCandidate — this is an operational/trust failure, never a security Finding', function () {
    $runner = new FakeProcessRunner(new ProcessResult(0, npmAuditFixtureJson('clean.json'), '', false, false, 10));
    $analyzer = makeNpmAuditAnalyzer($runner);
    $context = npmDiscoveryContext('npm-unsafe-npmrc');

    $result = $analyzer->run($context);

    expect($result->status)->toBe(ExecutionStatus::Failed);
    expect($analyzer->candidates($context, $result))->toBe([]);
});

it('does not fail closed for a normal project with no .npmrc at all', function () {
    $runner = new FakeProcessRunner(new ProcessResult(0, npmAuditFixtureJson('clean.json'), '', false, false, 10));
    $analyzer = makeNpmAuditAnalyzer($runner);
    $context = npmDiscoveryContext('laravel-inertia-react-ts');

    expect($analyzer->run($context)->status)->toBe(ExecutionStatus::Passed);
});
