<?php

use App\Audit\Analyzers\Semgrep\SemgrepParser;
use App\Audit\Analyzers\Semgrep\SemgrepRuleCatalog;

function semgrepFixture(string $name): string
{
    return file_get_contents(dirname(__DIR__, 4).'/Fixtures/semgrep/captured-json/'.$name);
}

it('parses a clean report with zero findings', function () {
    $report = (new SemgrepParser)->parse(semgrepFixture('clean.json'), SemgrepRuleCatalog::ruleIds());

    expect($report)->not->toBeNull()
        ->and($report->findings)->toBe([])
        ->and($report->errors)->toBe([])
        ->and($report->skipped)->toBe([])
        ->and($report->scanned)->toBe(['/targets/app/Clean.php'])
        ->and($report->semgrepVersion)->toBe('1.176.0');
});

it('parses real findings for known rule ids, preserving location, message, severity and metadata', function () {
    $report = (new SemgrepParser)->parse(semgrepFixture('with-findings.json'), SemgrepRuleCatalog::ruleIds());

    expect($report)->not->toBeNull()
        ->and($report->findings)->toHaveCount(2);

    $dd = $report->findings[0];
    expect($dd->ruleId)->toBe('laradogs.quality.debug.dd-call')
        ->and($dd->path)->toBe('/targets/app/Debug.php')
        ->and($dd->startLine)->toBe(3)
        ->and($dd->startColumn)->toBe(5)
        ->and($dd->endLine)->toBe(3)
        ->and($dd->endColumn)->toBe(14)
        ->and($dd->rawSeverity)->toBe('WARNING');

    $eval = $report->findings[1];
    expect($eval->ruleId)->toBe('laradogs.security.php.eval-usage')
        ->and($eval->rawSeverity)->toBe('ERROR')
        ->and($eval->metadata['cwe'])->toBe(["CWE-95: Improper Neutralization of Directives in Dynamically Evaluated Code ('Eval Injection')"])
        ->and($eval->metadata['references'])->toBe(['https://cwe.mitre.org/data/definitions/95.html']);
});

it('drops a result whose check_id matches no known rule id, never misattributing it', function () {
    $report = (new SemgrepParser)->parse(semgrepFixture('unrecognized-rule.json'), SemgrepRuleCatalog::ruleIds());

    expect($report)->not->toBeNull()
        ->and($report->findings)->toBe([]);
});

it('matches a check_id prefixed by a mangled config-path directory, not just an exact rule id', function () {
    $prefixed = json_encode([
        'version' => '1.176.0',
        'results' => [[
            'check_id' => 'var.folders.xyz.rules.laradogs.quality.debug.dd-call',
            'path' => '/targets/app/Debug.php',
            'start' => ['line' => 3, 'col' => 5, 'offset' => 20],
            'end' => ['line' => 3, 'col' => 14, 'offset' => 29],
            'extra' => ['message' => 'dd() call', 'severity' => 'WARNING', 'metadata' => []],
        ]],
        'errors' => [],
        'paths' => ['scanned' => ['/targets/app/Debug.php'], 'skipped' => []],
    ]);

    $report = (new SemgrepParser)->parse($prefixed, SemgrepRuleCatalog::ruleIds());

    expect($report->findings)->toHaveCount(1)
        ->and($report->findings[0]->ruleId)->toBe('laradogs.quality.debug.dd-call');
});

it('normalizes an array-form error type (e.g. PartialParsing) to just its type name', function () {
    $report = (new SemgrepParser)->parse(semgrepFixture('with-partial-parsing.json'), SemgrepRuleCatalog::ruleIds());

    expect($report)->not->toBeNull()
        ->and($report->errors)->toHaveCount(1)
        ->and($report->errors[0]['type'])->toBe('PartialParsing')
        ->and($report->errors[0]['level'])->toBe('warn')
        ->and($report->errors[0]['path'])->toBe('/targets/app/Bad.php')
        ->and($report->findings)->toHaveCount(1);
});

it('parses a benign wrong_language skip', function () {
    $report = (new SemgrepParser)->parse(semgrepFixture('with-benign-skip.json'), SemgrepRuleCatalog::ruleIds());

    expect($report->skipped)->toBe([
        ['path' => '/targets/resources/js/app.js', 'reason' => 'wrong_language'],
    ]);
});

it('parses a dangerous exceeded_size_limit skip', function () {
    $report = (new SemgrepParser)->parse(semgrepFixture('with-dangerous-skip.json'), SemgrepRuleCatalog::ruleIds());

    expect($report->skipped)->toBe([
        ['path' => '/targets/app/Huge.php', 'reason' => 'exceeded_size_limit'],
    ]);
});

it('parses an invalid-rule-config error report rather than crashing (the caller decides this is Failed via exit code)', function () {
    $report = (new SemgrepParser)->parse(semgrepFixture('invalid-rule-config-error.json'), SemgrepRuleCatalog::ruleIds());

    expect($report)->not->toBeNull()
        ->and($report->findings)->toBe([])
        ->and($report->errors)->toHaveCount(2)
        ->and($report->errors[1]['type'])->toBe('SemgrepError');
});

it('fails closed on malformed JSON rather than reporting a false-clean report', function () {
    expect((new SemgrepParser)->parse('{not valid json', SemgrepRuleCatalog::ruleIds()))->toBeNull();
});

it('fails closed when the top-level shape is missing results/errors/paths', function () {
    expect((new SemgrepParser)->parse(json_encode(['unexpected' => true]), SemgrepRuleCatalog::ruleIds()))->toBeNull();
});
