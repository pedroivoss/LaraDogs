<?php

use App\Audit\Analyzers\Semgrep\SemgrepRuleCatalog;
use Tests\TestCase;

uses(TestCase::class);

it('points rulesFilePath() at a real, existing YAML file', function () {
    $path = SemgrepRuleCatalog::rulesFilePath();

    expect(is_file($path))->toBeTrue()
        ->and($path)->toEndWith('.yml');
});

it('never drifts from the bundled YAML file — every catalog rule id appears verbatim in it', function () {
    $yaml = file_get_contents(SemgrepRuleCatalog::rulesFilePath());

    foreach (SemgrepRuleCatalog::ruleIds() as $ruleId) {
        expect($yaml)->toContain("id: {$ruleId}");
    }
});

it('returns exactly the ids find() can look up, and nothing for an unknown id', function () {
    foreach (SemgrepRuleCatalog::ruleIds() as $ruleId) {
        expect(SemgrepRuleCatalog::find($ruleId))->not->toBeNull();
    }

    expect(SemgrepRuleCatalog::find('not.a.real.rule'))->toBeNull();
});

it('is a deliberately small ruleset (2-5 rules) — this phase proves the vertical, not a full catalog', function () {
    expect(count(SemgrepRuleCatalog::ruleIds()))->toBeGreaterThanOrEqual(2)
        ->and(count(SemgrepRuleCatalog::ruleIds()))->toBeLessThanOrEqual(5);
});
