<?php

use App\Audit\Analyzers\Semgrep\SemgrepBinaryResolver;
use App\Audit\Analyzers\Semgrep\SemgrepParser;
use App\Audit\Analyzers\Semgrep\SemgrepRuleCatalog;
use App\Audit\Analyzers\Semgrep\SemgrepScanReport;
use App\Audit\Engine\Process\ProcessCommand;
use App\Audit\Engine\Process\SymfonyProcessRunner;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Opt-in, real-`semgrep`-binary validation of every Phase 6 Laravel-aware
 * rule — the "rule quality gate" the phase's own spec requires: for each
 * rule, prove positive fixtures ARE flagged, and negative/safe fixtures
 * are NOT flagged, using the REAL semgrep binary (never FakeProcessRunner)
 * against the real bundled YAML ruleset. Skipped by default — never
 * required for the automated suite to pass. Run explicitly with:
 *
 *   LARADOGS_TEST_REAL_SEMGREP=1 php artisan test --filter=SemgrepLaravelRulesRealBinaryTest
 */
function skipUnlessRealSemgrepRules(TestCase $test): void
{
    if (getenv('LARADOGS_TEST_REAL_SEMGREP') !== '1') {
        $test->markTestSkipped('Set LARADOGS_TEST_REAL_SEMGREP=1 to run this opt-in test against the real semgrep binary.');
    }
}

/**
 * Runs the real semgrep binary against one or more fixture files using the
 * real bundled ruleset, exactly the way SemgrepAnalyzer itself invokes it
 * (cwd = the rules' own directory, --config = the bare filename, targets =
 * absolute file paths) — but bypassing the analyzer/Discovery layers
 * entirely, since this test validates the RULES themselves, not the
 * analyzer plumbing already covered by SemgrepAnalyzerTest/
 * SemgrepAuditRealBinaryTest.
 */
function scanFixturesWithRealSemgrep(string ...$fixturePaths): SemgrepScanReport
{
    $binary = (new SemgrepBinaryResolver)->resolve();
    expect($binary)->not->toBeNull('semgrep binary could not be resolved.');

    $rulesFile = SemgrepRuleCatalog::rulesFilePath();
    $runner = new SymfonyProcessRunner;

    $result = $runner->run(new ProcessCommand(
        argv: [
            $binary, 'scan',
            '--config', basename($rulesFile),
            '--json',
            '--verbose',
            '--metrics=off',
            '--no-git-ignore',
            '--oss-only',
            ...$fixturePaths,
        ],
        workingDirectory: dirname($rulesFile),
        environment: [
            'PATH' => (string) getenv('PATH'),
            'SEMGREP_SETTINGS_FILE' => (string) config('laradogs.semgrep.settings_path'),
            'SEMGREP_SEND_METRICS' => 'off',
        ],
        timeoutSeconds: 30,
    ));

    expect($result->successful())->toBeTrue("semgrep scan failed: {$result->stderr}");

    $report = (new SemgrepParser)->parse($result->stdout, SemgrepRuleCatalog::ruleIds());

    expect($report)->not->toBeNull();

    return $report;
}

/**
 * @return list<array{rule_id: string, line: int}>
 */
function scanFixtureWithRealSemgrep(string $fixturePath): array
{
    $report = scanFixturesWithRealSemgrep($fixturePath);

    expect($report)->not->toBeNull();

    return array_map(
        fn ($finding) => ['rule_id' => $finding->ruleId, 'line' => $finding->startLine],
        $report->findings,
    );
}

/**
 * @param  list<array{rule_id: string, line: int}>  $findings
 * @return list<int>
 */
function linesForRule(array $findings, string $ruleId): array
{
    return array_values(array_map(
        fn ($f) => $f['line'],
        array_filter($findings, fn ($f) => $f['rule_id'] === $ruleId),
    ));
}

function rulesFixture(string $name): string
{
    return dirname(__DIR__, 4).'/Fixtures/semgrep/rules/'.$name;
}

it('flags every SQL raw-query positive and none of the negative/safe cases', function () {
    skipUnlessRealSemgrepRules($this);

    $findings = scanFixtureWithRealSemgrep(rulesFixture('sql-raw-query.php'));
    $lines = linesForRule($findings, 'laradogs.security.sql.tainted-raw-query');

    expect($lines)->toEqualCanonicalizing([17, 25, 33, 39]);
});

it('flags every Blade raw-output positive and none of the negative/safe cases', function () {
    skipUnlessRealSemgrepRules($this);

    $findings = scanFixtureWithRealSemgrep(rulesFixture('blade-raw-output.blade.php'));
    $lines = linesForRule($findings, 'laradogs.security.blade.raw-output-tainted');

    expect($lines)->toEqualCanonicalizing([5, 8, 11]);
});

it('flags every command-execution positive and none of the negative/safe cases', function () {
    skipUnlessRealSemgrepRules($this);

    $findings = scanFixtureWithRealSemgrep(rulesFixture('command-exec.php'));
    $lines = linesForRule($findings, 'laradogs.security.command.tainted-exec');

    expect($lines)->toEqualCanonicalizing([14, 21, 28, 34]);
});

it('flags every filesystem-path positive and none of the negative/safe cases', function () {
    skipUnlessRealSemgrepRules($this);

    $findings = scanFixtureWithRealSemgrep(rulesFixture('filesystem-path.php'));
    $lines = linesForRule($findings, 'laradogs.security.filesystem.tainted-path');

    expect($lines)->toEqualCanonicalizing([17, 24, 32, 38]);
});

it('flags every open-redirect positive and none of the negative/safe cases', function () {
    skipUnlessRealSemgrepRules($this);

    $findings = scanFixtureWithRealSemgrep(rulesFixture('open-redirect.php'));
    $lines = linesForRule($findings, 'laradogs.security.redirect.tainted-open-redirect');

    expect($lines)->toEqualCanonicalizing([15, 23, 31]);
});

it('flags every mass-assignment positive and none of the negative/safe cases', function () {
    skipUnlessRealSemgrepRules($this);

    $findings = scanFixtureWithRealSemgrep(rulesFixture('mass-assignment.php'));
    $lines = linesForRule($findings, 'laradogs.security.mass-assignment.request-all');

    expect($lines)->toEqualCanonicalizing([15, 21]);

    // The known, documented cross-rule risk this test also guards against:
    // the performance rule must NOT also fire on $request->all() nested
    // inside these same lines (see the YAML's own comment on why the
    // performance rule restricts $MODEL to a capitalized receiver).
    expect(linesForRule($findings, 'laradogs.performance.eloquent.unbounded-all'))->toBe([]);
});

it('flags the ray() debug call positive and not the unrelated/method-call negatives', function () {
    skipUnlessRealSemgrepRules($this);

    $findings = scanFixtureWithRealSemgrep(rulesFixture('ray-call.php'));
    $lines = linesForRule($findings, 'laradogs.quality.debug.ray-call');

    expect($lines)->toEqualCanonicalizing([13, 21]);
});

it('flags env(APP_DEBUG, true) and not the safe-default/no-default/unrelated-key negatives', function () {
    skipUnlessRealSemgrepRules($this);

    $findings = scanFixtureWithRealSemgrep(rulesFixture('app-debug-config.php'));
    $lines = linesForRule($findings, 'laradogs.configuration.debug.app-debug-default-true');

    expect($lines)->toEqualCanonicalizing([15]);
});

it('flags Model::all() and not paginate()/limit()/cursor() alternatives, nor $request->all()', function () {
    skipUnlessRealSemgrepRules($this);

    $findings = scanFixtureWithRealSemgrep(rulesFixture('eloquent-unbounded-all.php'));
    $lines = linesForRule($findings, 'laradogs.performance.eloquent.unbounded-all');

    expect($lines)->toEqualCanonicalizing([15]);
});

it('finds zero unexpected results and zero errors when scanning all Phase 6 fixtures together', function () {
    skipUnlessRealSemgrepRules($this);

    $fixtureFiles = glob(rulesFixture('*'));
    expect($fixtureFiles)->not->toBeEmpty();

    $report = scanFixturesWithRealSemgrep(...$fixtureFiles);

    expect($report->errors)->toBe([])
        ->and(count($report->findings))->toBe(24);
});
