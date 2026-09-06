<?php

use App\Audit\Findings\Redaction\EvidenceRedactor;

it('redacts an AWS-style access key id', function () {
    $redactor = new EvidenceRedactor;

    $result = $redactor->redact('aws_key = AKIAABCDEFGHIJKLMNOP');

    expect($result)->not->toContain('AKIAABCDEFGHIJKLMNOP');
    expect($result)->toContain('AKIA');
    expect($result)->toContain('****');
});

it('redacts an obvious KEY=VALUE secret assignment', function () {
    $redactor = new EvidenceRedactor;

    $result = $redactor->redact('AWS_SECRET_ACCESS_KEY=abcdefghijklmnopqrstuvwxyz1234567890');

    expect($result)->not->toContain('abcdefghijklmnopqrstuvwxyz1234567890');
    expect($result)->toContain('AWS_SECRET_ACCESS_KEY=');
    expect($result)->toContain('****');
});

it('leaves ordinary code untouched', function () {
    $redactor = new EvidenceRedactor;
    $code = 'DB::select("SELECT * FROM users WHERE id = " . $id);';

    expect($redactor->redact($code))->toBe($code);
});

it('passes through null and empty strings unchanged', function () {
    $redactor = new EvidenceRedactor;

    expect($redactor->redact(null))->toBeNull();
    expect($redactor->redact(''))->toBe('');
});

it('redacts string values inside a metadata array, one level deep', function () {
    $redactor = new EvidenceRedactor;

    $result = $redactor->redactArray([
        'package' => 'guzzlehttp/psr7',
        'leaked' => 'API_TOKEN=supersecrettokenvalue123456',
        'count' => 3,
    ]);

    expect($result['package'])->toBe('guzzlehttp/psr7');
    expect($result['leaked'])->not->toContain('supersecrettokenvalue123456');
    expect($result['count'])->toBe(3);
});
