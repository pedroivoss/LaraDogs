<?php

use App\Audit\Analyzers\Composer\ComposerAuditParser;

function composerAuditFixture(string $name): string
{
    return file_get_contents(dirname(__DIR__, 4).'/Fixtures/composer-audit/'.$name);
}

it('parses a clean report with no advisories or abandoned packages', function () {
    $report = (new ComposerAuditParser)->parse(composerAuditFixture('clean.json'));

    expect($report)->not->toBeNull()
        ->and($report->advisories)->toBe([])
        ->and($report->abandoned)->toBe([])
        ->and($report->hasUnreachableRepositories)->toBeFalse();
});

it('parses advisories, including one with a null severity', function () {
    $report = (new ComposerAuditParser)->parse(composerAuditFixture('with-advisories.json'));

    expect($report)->not->toBeNull()
        ->and($report->advisories)->toHaveCount(2);

    $withSeverity = $report->advisories[0];
    expect($withSeverity->packageName)->toBe('vendor/vulnerable-package')
        ->and($withSeverity->advisoryId)->toBe('PKSA-abcd-1234-efgh')
        ->and($withSeverity->severity)->toBe('high')
        ->and($withSeverity->cve)->toBe('CVE-2025-00001')
        ->and($withSeverity->sources)->toHaveCount(1);

    $withoutSeverity = $report->advisories[1];
    expect($withoutSeverity->packageName)->toBe('vendor/no-severity-package')
        ->and($withoutSeverity->severity)->toBeNull()
        ->and($withoutSeverity->cve)->toBeNull()
        ->and($withoutSeverity->link)->toBeNull();
});

it('parses abandoned packages, both with and without a suggested replacement', function () {
    $report = (new ComposerAuditParser)->parse(composerAuditFixture('with-abandoned.json'));

    expect($report)->not->toBeNull()
        ->and($report->abandoned)->toBe([
            'vendor/abandoned-with-replacement' => 'vendor/new-package',
            'vendor/abandoned-no-replacement' => null,
        ]);
});

it('flags unreachable repositories as a distinct, non-clean signal', function () {
    $report = (new ComposerAuditParser)->parse(composerAuditFixture('with-unreachable-repositories.json'));

    expect($report)->not->toBeNull()
        ->and($report->hasUnreachableRepositories)->toBeTrue()
        ->and($report->unreachableRepositories)->toBe(['https://packagist.org']);
});

it('returns null for malformed JSON rather than crashing', function () {
    $report = (new ComposerAuditParser)->parse(composerAuditFixture('malformed.json'));

    expect($report)->toBeNull();
});

it('returns null for truncated JSON rather than silently treating it as valid', function () {
    $report = (new ComposerAuditParser)->parse(composerAuditFixture('truncated.json'));

    expect($report)->toBeNull();
});

it('returns null for a JSON value that is not an object', function () {
    $report = (new ComposerAuditParser)->parse('"just a string"');

    expect($report)->toBeNull();
});

it('returns null for empty input', function () {
    $report = (new ComposerAuditParser)->parse('');

    expect($report)->toBeNull();
});

it('tolerates an advisory entry missing optional fields without crashing', function () {
    $json = json_encode([
        'advisories' => [
            'vendor/pkg' => [
                ['advisoryId' => 'PKSA-minimal', 'title' => 'Minimal advisory'],
            ],
        ],
        'abandoned' => [],
    ]);

    $report = (new ComposerAuditParser)->parse($json);

    expect($report)->not->toBeNull()
        ->and($report->advisories)->toHaveCount(1)
        ->and($report->advisories[0]->packageName)->toBe('vendor/pkg')
        ->and($report->advisories[0]->affectedVersions)->toBe('');
});

it('skips (rather than crashes on) an advisory entry missing required identity fields', function () {
    $json = json_encode([
        'advisories' => [
            'vendor/pkg' => [
                ['title' => 'No advisoryId here'],
                ['advisoryId' => 'PKSA-valid', 'title' => 'This one is valid'],
            ],
        ],
        'abandoned' => [],
    ]);

    $report = (new ComposerAuditParser)->parse($json);

    expect($report)->not->toBeNull()
        ->and($report->advisories)->toHaveCount(1)
        ->and($report->advisories[0]->advisoryId)->toBe('PKSA-valid');
});

it('tolerates an unexpected top-level shape for advisories without crashing', function () {
    $report = (new ComposerAuditParser)->parse(json_encode(['advisories' => 'not-an-array']));

    expect($report)->toBeNull();
});

it('tolerates a non-array abandoned value by returning an empty map', function () {
    $report = (new ComposerAuditParser)->parse(json_encode(['advisories' => [], 'abandoned' => 'unexpected']));

    expect($report)->not->toBeNull()
        ->and($report->abandoned)->toBe([]);
});
