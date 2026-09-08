<?php

use App\Audit\Analyzers\Semgrep\SemgrepCoverageEvaluator;
use App\Audit\Analyzers\Semgrep\SemgrepScanReport;

it('is fully covered when there are zero errors and zero skips', function () {
    $report = new SemgrepScanReport(findings: [], errors: [], skipped: [], scanned: ['a.php'], semgrepVersion: '1.176.0');

    expect((new SemgrepCoverageEvaluator)->isFullyCovered($report))->toBeTrue();
});

it('is fully covered when the only skips are benign (wrong_language, excluded_by_config)', function () {
    $report = new SemgrepScanReport(
        findings: [],
        errors: [],
        skipped: [
            ['path' => 'a.js', 'reason' => 'wrong_language'],
            ['path' => 'b.php', 'reason' => 'excluded_by_config'],
        ],
        scanned: ['a.php'],
        semgrepVersion: '1.176.0',
    );

    expect((new SemgrepCoverageEvaluator)->isFullyCovered($report))->toBeTrue();
});

it('is NOT fully covered when any error is present at all, even a mere warning', function () {
    $report = new SemgrepScanReport(
        findings: [],
        errors: [['code' => 3, 'level' => 'warn', 'type' => 'PartialParsing', 'message' => 'x', 'path' => 'bad.php']],
        skipped: [],
        scanned: ['good.php'],
        semgrepVersion: '1.176.0',
    );

    expect((new SemgrepCoverageEvaluator)->isFullyCovered($report))->toBeFalse();
});

it('is NOT fully covered when a first-party file was skipped for exceeding the size limit', function () {
    $report = new SemgrepScanReport(
        findings: [],
        errors: [],
        skipped: [['path' => 'huge.php', 'reason' => 'exceeded_size_limit']],
        scanned: [],
        semgrepVersion: '1.176.0',
    );

    expect((new SemgrepCoverageEvaluator)->isFullyCovered($report))->toBeFalse();
});

it('is NOT fully covered when a first-party file was skipped due to an internal parser error', function () {
    $report = new SemgrepScanReport(
        findings: [],
        errors: [],
        skipped: [['path' => 'weird.php', 'reason' => 'analysis_failed_parser_or_internal_error']],
        scanned: [],
        semgrepVersion: '1.176.0',
    );

    expect((new SemgrepCoverageEvaluator)->isFullyCovered($report))->toBeFalse();
});

it('is NOT fully covered for an unrecognized/future skip reason — conservative allowlist, not a blocklist', function () {
    $report = new SemgrepScanReport(
        findings: [],
        errors: [],
        skipped: [['path' => 'mystery.php', 'reason' => 'some_future_reason_this_codebase_has_never_seen']],
        scanned: [],
        semgrepVersion: '1.176.0',
    );

    expect((new SemgrepCoverageEvaluator)->isFullyCovered($report))->toBeFalse();
});
