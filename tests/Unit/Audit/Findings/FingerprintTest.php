<?php

use App\Audit\Findings\Fingerprint\Fingerprinter;
use Tests\Support\Findings\SyntheticCandidates;

it('is deterministic for the same candidate', function () {
    $fingerprinter = new Fingerprinter;
    $candidate = SyntheticCandidates::sqlInjection();

    expect($fingerprinter->fingerprint($candidate))->toBe($fingerprinter->fingerprint($candidate));
});

it('is stable across a line number change alone', function () {
    $fingerprinter = new Fingerprinter;

    $before = SyntheticCandidates::sqlInjection(lineStart: 42, lineEnd: 44);
    $after = SyntheticCandidates::sqlInjection(lineStart: 58, lineEnd: 60);

    expect($fingerprinter->fingerprint($before))->toBe($fingerprinter->fingerprint($after));
});

it('is stable across whitespace-only reformatting of the code snippet', function () {
    $fingerprinter = new Fingerprinter;

    $a = SyntheticCandidates::sqlInjection(codeSnippet: 'DB::select("SELECT * FROM users WHERE id = " . $id);');
    $b = SyntheticCandidates::sqlInjection(codeSnippet: "DB::select(\"SELECT * FROM users WHERE id = \"\n    . \$id);");

    expect($fingerprinter->fingerprint($a))->toBe($fingerprinter->fingerprint($b));
});

it('changes when the rule id changes, even for the same file/code', function () {
    $fingerprinter = new Fingerprinter;

    $a = SyntheticCandidates::sqlInjection(ruleId: 'LARA-SEC-023');
    $b = SyntheticCandidates::sqlInjection(ruleId: 'LARA-SEC-099');

    expect($fingerprinter->fingerprint($a))->not->toBe($fingerprinter->fingerprint($b));
});

it('changes when the analyzer id changes, even for the same rule/file/code', function () {
    $fingerprinter = new Fingerprinter;

    $a = SyntheticCandidates::sqlInjection(analyzerId: 'composer-security');
    $b = SyntheticCandidates::sqlInjection(analyzerId: 'semgrep');

    expect($fingerprinter->fingerprint($a))->not->toBe($fingerprinter->fingerprint($b));
});

it('changes when the file path changes', function () {
    $fingerprinter = new Fingerprinter;

    $a = SyntheticCandidates::sqlInjection(filePath: 'app/Repositories/UserRepository.php');
    $b = SyntheticCandidates::sqlInjection(filePath: 'app/Repositories/PostRepository.php');

    expect($fingerprinter->fingerprint($a))->not->toBe($fingerprinter->fingerprint($b));
});

it('changes when the code snippet content actually differs', function () {
    $fingerprinter = new Fingerprinter;

    $a = SyntheticCandidates::sqlInjection(codeSnippet: 'DB::select("SELECT * FROM users WHERE id = " . $id);');
    $b = SyntheticCandidates::sqlInjection(codeSnippet: 'DB::select("SELECT * FROM posts WHERE id = " . $id);');

    expect($fingerprinter->fingerprint($a))->not->toBe($fingerprinter->fingerprint($b));
});

it('handles candidates with no file/line (e.g. a dependency finding) without error', function () {
    $fingerprinter = new Fingerprinter;
    $candidate = SyntheticCandidates::vulnerableDependency();

    expect($fingerprinter->fingerprint($candidate))->toBeString()->not->toBeEmpty();
});

it('is a versioned, 64-character hex string (sha256)', function () {
    expect(Fingerprinter::VERSION)->toBe('v1');

    $fingerprint = (new Fingerprinter)->fingerprint(SyntheticCandidates::sqlInjection());

    expect($fingerprint)->toMatch('/^[a-f0-9]{64}$/');
});
