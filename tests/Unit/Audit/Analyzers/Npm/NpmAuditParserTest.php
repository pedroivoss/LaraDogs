<?php

use App\Audit\Analyzers\Npm\NpmAuditParser;

function npmAuditFixture(string $name): string
{
    return file_get_contents(dirname(__DIR__, 4).'/Fixtures/npm-audit/'.$name);
}

it('parses a clean report with no vulnerabilities', function () {
    $report = (new NpmAuditParser)->parse(npmAuditFixture('clean.json'));

    expect($report)->not->toBeNull()
        ->and($report->advisories)->toBe([])
        ->and($report->severityCounts['total'])->toBe(0);
});

it('parses a direct vulnerability with multiple real advisory objects', function () {
    $report = (new NpmAuditParser)->parse(npmAuditFixture('with-direct-vulnerability.json'));

    expect($report)->not->toBeNull()
        ->and($report->advisories)->toHaveCount(2);

    $first = $report->advisories[0];
    expect($first->packageName)->toBe('lodash')
        ->and($first->isDirect)->toBeTrue()
        ->and($first->source)->toBe(1106900)
        ->and($first->severity)->toBe('moderate')
        ->and($first->range)->toBe('<4.17.5')
        ->and($first->packageRange)->toBe('<=4.17.23')
        ->and($first->fixAvailable)->toBeTrue()
        ->and($first->nodes)->toBe(['node_modules/lodash'])
        ->and($first->cwe)->toBe(['CWE-471', 'CWE-1321']);
});

it('extracts only real advisory objects from via, skipping plain-string cross-references', function () {
    $report = (new NpmAuditParser)->parse(npmAuditFixture('with-transitive-vulnerability.json'));

    expect($report)->not->toBeNull();

    // mocha's own `via` is entirely strings (["debug"]) — no real advisory
    // of its own, so it must produce ZERO NpmAdvisory entries.
    $mochaAdvisories = array_filter($report->advisories, fn ($a) => $a->packageName === 'mocha');
    expect($mochaAdvisories)->toBe([]);

    // debug's via has one real object plus a string ("ms") — only the
    // object becomes an advisory.
    $debugAdvisories = array_values(array_filter($report->advisories, fn ($a) => $a->packageName === 'debug'));
    expect($debugAdvisories)->toHaveCount(1)
        ->and($debugAdvisories[0]->source)->toBe(1094457)
        ->and($debugAdvisories[0]->isDirect)->toBeFalse();

    // ms has its own real advisory too.
    $msAdvisories = array_values(array_filter($report->advisories, fn ($a) => $a->packageName === 'ms'));
    expect($msAdvisories)->toHaveCount(1)
        ->and($msAdvisories[0]->source)->toBe(1109573);

    // Total: exactly 2 real advisories across the whole report (debug + ms),
    // never 3 (mocha contributes none) and never duplicated.
    expect($report->advisories)->toHaveCount(2);

    $fixAvailable = $debugAdvisories[0]->fixAvailable;
    expect($fixAvailable)->toBeArray()
        ->and($fixAvailable['name'])->toBe('mocha')
        ->and($fixAvailable['version'])->toBe('12.0.0')
        ->and($fixAvailable['is_semver_major'])->toBeTrue();
});

it('returns null for malformed JSON rather than crashing', function () {
    expect((new NpmAuditParser)->parse(npmAuditFixture('malformed.json')))->toBeNull();
});

it('returns null for truncated JSON rather than silently treating it as valid', function () {
    expect((new NpmAuditParser)->parse(npmAuditFixture('truncated.json')))->toBeNull();
});

it('returns null for a registry/network error response, never a false-clean report', function () {
    expect((new NpmAuditParser)->parse(npmAuditFixture('registry-error.json')))->toBeNull();
});

it('returns null for a missing-lockfile error response', function () {
    expect((new NpmAuditParser)->parse(npmAuditFixture('no-lockfile-error.json')))->toBeNull();
});

it('returns null for an unrecognized/older schema shape rather than treating it as clean', function () {
    expect((new NpmAuditParser)->parse(npmAuditFixture('unexpected-schema-v1.json')))->toBeNull();
});

it('returns null for a JSON value that is not an object', function () {
    expect((new NpmAuditParser)->parse('"just a string"'))->toBeNull();
});

it('returns null for empty input', function () {
    expect((new NpmAuditParser)->parse(''))->toBeNull();
});

it('tolerates an advisory entry missing optional fields without crashing', function () {
    $json = json_encode([
        'auditReportVersion' => 2,
        'vulnerabilities' => [
            'pkg' => [
                'via' => [
                    ['source' => 42, 'title' => 'Minimal advisory'],
                ],
            ],
        ],
        'metadata' => ['vulnerabilities' => ['info' => 0, 'low' => 0, 'moderate' => 0, 'high' => 0, 'critical' => 0, 'total' => 0]],
    ]);

    $report = (new NpmAuditParser)->parse($json);

    expect($report)->not->toBeNull()
        ->and($report->advisories)->toHaveCount(1)
        ->and($report->advisories[0]->packageName)->toBe('pkg')
        ->and($report->advisories[0]->isDirect)->toBeFalse()
        ->and($report->advisories[0]->severity)->toBeNull()
        ->and($report->advisories[0]->fixAvailable)->toBeFalse();
});

it('skips a via entry that is an object without a valid source id', function () {
    $json = json_encode([
        'auditReportVersion' => 2,
        'vulnerabilities' => [
            'pkg' => [
                'via' => [
                    ['title' => 'No source id here'],
                    ['source' => 99, 'title' => 'Valid one'],
                ],
            ],
        ],
        'metadata' => ['vulnerabilities' => ['total' => 0]],
    ]);

    $report = (new NpmAuditParser)->parse($json);

    expect($report)->not->toBeNull()
        ->and($report->advisories)->toHaveCount(1)
        ->and($report->advisories[0]->source)->toBe(99);
});

it('tolerates an unexpected type for vulnerabilities without crashing', function () {
    expect((new NpmAuditParser)->parse(json_encode(['auditReportVersion' => 2, 'vulnerabilities' => 'not-an-array'])))->toBeNull();
});

it('requires metadata.vulnerabilities to be present and array-shaped', function () {
    expect((new NpmAuditParser)->parse(json_encode(['auditReportVersion' => 2, 'vulnerabilities' => []])))->toBeNull();
    expect((new NpmAuditParser)->parse(json_encode(['auditReportVersion' => 2, 'vulnerabilities' => [], 'metadata' => 'nope'])))->toBeNull();
});
