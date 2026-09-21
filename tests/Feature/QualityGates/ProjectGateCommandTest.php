<?php

use App\Audit\Engine\Execution\AnalyzerCoverage;
use App\Audit\Engine\Registry\AnalyzerRegistry;
use App\Audit\Findings\ScanOrigin;
use App\Audit\Projects\AuditSchedule;
use App\Audit\Projects\DispatchDueProjectAudits;
use App\Audit\Projects\RunProjectAudit;
use App\Audit\QualityGates\Policy\AnalyzerStatusRule;
use App\Audit\QualityGates\Policy\MaxOpenFindingsRule;
use App\Audit\QualityGates\Policy\QualityGatePolicy;
use App\Audit\QualityGates\QualityGatePolicyService;
use App\Console\Commands\ProjectGateCommand;
use App\Jobs\RunProjectAuditJob;
use App\Models\Audit\QualityGateResult;
use App\Models\Audit\Scan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Tests\Support\Engine\Analyzers\AlwaysPassAnalyzer;
use Tests\Support\Engine\Analyzers\TimedOutAnalyzer;
use Tests\Support\QualityGates\GateScans;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function qgGateJson(array $args): array
{
    $exit = Artisan::call('laradogs:project:gate', [...$args, '--json' => true]);

    return [$exit, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)];
}

// ---------------- exit codes: the V1 contract ----------------

it('documents and exposes the exact V1 exit codes', function () {
    expect(ProjectGateCommand::EXIT_OPERATIONAL_ERROR)->toBe(3)
        ->and(ProjectGateCommand::EXIT_NOT_EVALUATED)->toBe(4);
});

it('exits 0 for a Passed gate', function () {
    $project = GateScans::project();
    GateScans::enable($project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));
    GateScans::scan($project);

    [$exit, $json] = qgGateJson(['project' => $project->public_id]);

    expect($exit)->toBe(0)
        ->and($json['gate']['outcome'])->toBe('passed')
        ->and($json['exit_code'])->toBe(0);
});

it('exits 1 for a Failed gate', function () {
    $project = GateScans::project();
    GateScans::enable($project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));
    GateScans::scan($project, candidatesByAnalyzer: ['semgrep' => [GateScans::candidate('a')]]);

    [$exit, $json] = qgGateJson(['project' => $project->public_id]);

    expect($exit)->toBe(1)
        ->and($json['gate']['outcome'])->toBe('failed')
        ->and($json['exit_code'])->toBe(1)
        ->and($json['gate']['rules_failed'])->toBe(1);
});

it('exits 2 for an Indeterminate gate (fail closed)', function () {
    $project = GateScans::project();
    GateScans::enable($project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));
    GateScans::scan($project, GateScans::analyzers(['semgrep' => new TimedOutAnalyzer('semgrep')]));

    [$exit, $json] = qgGateJson(['project' => $project->public_id]);

    expect($exit)->toBe(2)
        ->and($json['gate']['outcome'])->toBe('indeterminate');
});

it('exits 3 for an unknown project, an unknown scan, or a project with no terminal scan', function () {
    $project = GateScans::project();

    [$exitProject, $jsonProject] = qgGateJson(['project' => 'no-such-project']);
    [$exitNoScan, $jsonNoScan] = qgGateJson(['project' => $project->public_id]);
    [$exitBadScan, $jsonBadScan] = qgGateJson(['project' => $project->public_id, '--scan' => 'no-such-scan']);

    expect($exitProject)->toBe(3)->and($jsonProject['gate'])->toBeNull()->and($jsonProject['exit_code'])->toBe(3)
        ->and($exitNoScan)->toBe(3)->and($jsonNoScan['error'])->toContain('no completed or failed scan')
        ->and($exitBadScan)->toBe(3);
});

it('exits 4 when the scan has no gate result — never 0', function () {
    $project = GateScans::project();
    $scan = GateScans::scan($project); // gate not enabled → no result

    [$exit, $json] = qgGateJson(['project' => $project->public_id]);

    expect($exit)->toBe(4)
        ->and($json['gate'])->toBeNull()
        ->and($json['scan'])->toBe($scan->public_id)
        ->and($json['exit_code'])->toBe(4);
});

// ---------------- scan selection ----------------

it('checks the latest terminal scan by default and a specific scan with --scan', function () {
    $project = GateScans::project();
    GateScans::enable($project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));
    $failing = GateScans::scan($project, candidatesByAnalyzer: ['semgrep' => [GateScans::candidate('a')]]);
    // resolve the finding → next scan passes
    GateScans::scan($project);

    [$latestExit] = qgGateJson(['project' => $project->public_id]);
    [$olderExit, $olderJson] = qgGateJson(['project' => $project->public_id, '--scan' => $failing->public_id]);

    expect($latestExit)->toBe(0)
        ->and($olderExit)->toBe(1)
        ->and($olderJson['scan'])->toBe($failing->public_id);
});

it('cannot check a scan that belongs to another project', function () {
    $one = GateScans::project('laravel-blade');
    $two = GateScans::project('laravel-api');
    GateScans::enable($one, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));
    $scanOfOne = GateScans::scan($one);

    [$exit] = qgGateJson(['project' => $two->public_id, '--scan' => $scanOfOne->public_id]);

    expect($exit)->toBe(3);
});

// ---------------- JSON contract ----------------

it('emits the stable JSON structure without host paths', function () {
    $project = GateScans::project();
    GateScans::enable($project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0]), new AnalyzerStatusRule(['semgrep'])]));
    GateScans::scan($project, candidatesByAnalyzer: ['semgrep' => [GateScans::candidate('a')]]);

    Artisan::call('laradogs:project:gate', ['project' => $project->public_id, '--json' => true]);
    $raw = Artisan::output();
    $json = json_decode($raw, true);

    expect(array_keys($json))->toBe(['project', 'scan', 'gate', 'exit_code'])
        ->and(array_keys($json['gate']))->toBe(['outcome', 'policy_revision', 'evaluated_at', 'baseline_scan', 'rules_total', 'rules_failed', 'rules_indeterminate', 'rules'])
        ->and(array_keys($json['gate']['rules'][0]))->toBe(['rule_id', 'subject', 'outcome', 'summary', 'observed', 'expected', 'analyzer_id', 'severity', 'finding_count', 'finding_ids'])
        ->and($json['gate']['rules'][0]['rule_id'])->toBe('laradogs.gate.max-open-findings')
        ->and($json['gate']['policy_revision'])->toBe(1)
        ->and($raw)->not->toContain($project->path);
});

it('prints a readable summary in text mode and keeps the same exit code', function () {
    $project = GateScans::project();
    GateScans::enable($project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));
    GateScans::scan($project, candidatesByAnalyzer: ['semgrep' => [GateScans::candidate('a')]]);

    $this->artisan('laradogs:project:gate', ['project' => $project->public_id])
        ->expectsOutputToContain('Quality Gate: Failed (policy revision 1')
        ->expectsOutputToContain('FAIL')
        ->assertExitCode(1);
});

// ---------------- reads only ----------------

it('reads the immutable result: it does not re-evaluate after the policy changes', function () {
    $project = GateScans::project();
    GateScans::enable($project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));
    GateScans::scan($project, candidatesByAnalyzer: ['semgrep' => [GateScans::candidate('a')]]);
    app(QualityGatePolicyService::class)->update($project, true, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 99])]));

    [$exit, $json] = qgGateJson(['project' => $project->public_id]);

    expect($exit)->toBe(1)                                   // still judged by revision 1
        ->and($json['gate']['policy_revision'])->toBe(1)
        ->and(QualityGateResult::query()->count())->toBe(1);
});

// ---------------- the audit command is additive and its exit code is unchanged ----------------

it('shows the gate in laradogs:project:audit output and JSON without changing its own exit code', function () {
    $project = GateScans::project();
    GateScans::enable($project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));
    App::instance(AnalyzerRegistry::class, GateScans::analyzers(['semgrep' => new AlwaysPassAnalyzer('semgrep', AnalyzerCoverage::explicit(['R1']))]));

    $exit = Artisan::call('laradogs:project:audit', ['project' => $project->public_id, '--json' => true]);
    $json = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)                                   // the audit itself succeeded
        ->and($json['succeeded'])->toBeTrue()
        ->and($json['quality_gate']['outcome'])->toBe('passed')
        ->and($json['quality_gate']['policy_revision'])->toBe(1);
});

it('reports a null quality_gate for a project without a gate', function () {
    $project = GateScans::project();
    App::instance(AnalyzerRegistry::class, GateScans::analyzers());

    Artisan::call('laradogs:project:audit', ['project' => $project->public_id, '--json' => true]);

    expect(json_decode(Artisan::output(), true)['quality_gate'])->toBeNull();
});

// ---------------- CLI / worker / scheduler converge on one evaluation ----------------

it('evaluates the gate exactly once for a CLI persisted audit', function () {
    $project = GateScans::project();
    GateScans::enable($project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));
    App::instance(AnalyzerRegistry::class, GateScans::analyzers());

    $scan = app(RunProjectAudit::class)->run($project, ScanOrigin::Cli)->scan;

    expect(QualityGateResult::query()->where('scan_id', $scan->id)->count())->toBe(1);
});

it('evaluates the gate automatically when the queue worker completes a Dashboard-triggered audit', function () {
    $project = GateScans::project();
    GateScans::enable($project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));
    App::instance(AnalyzerRegistry::class, GateScans::analyzers());

    $scan = app(RunProjectAudit::class)->enqueue($project, ScanOrigin::Manual)->scan;
    expect(QualityGateResult::query()->count())->toBe(0);          // Queued: never gated

    (new RunProjectAuditJob($scan->id))->handle(app(RunProjectAudit::class));

    expect(QualityGateResult::query()->where('scan_id', $scan->id)->count())->toBe(1)
        ->and(QualityGateResult::query()->firstOrFail()->outcome->value)->toBe('passed');
});

it('evaluates the gate for a scheduled audit through the very same path', function () {
    Bus::fake();
    $project = GateScans::project();
    GateScans::enable($project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));
    $project->audit_schedule = AuditSchedule::Daily;
    $project->next_audit_at = now()->subMinute();
    $project->save();
    App::instance(AnalyzerRegistry::class, GateScans::analyzers());

    app(DispatchDueProjectAudits::class)->dispatch();
    $scan = Scan::query()->firstOrFail();
    expect($scan->origin)->toBe(ScanOrigin::Scheduled);

    (new RunProjectAuditJob($scan->id))->handle(app(RunProjectAudit::class));

    expect(QualityGateResult::query()->where('scan_id', $scan->id)->count())->toBe(1);
});

it('produces an Indeterminate result when a queued audit fails before running (path vanished)', function () {
    $project = GateScans::project();
    GateScans::enable($project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));
    $scan = app(RunProjectAudit::class)->enqueue($project, ScanOrigin::Manual)->scan;
    $project->path = '/definitely/not/a/real/path-'.uniqid();
    $project->save();

    (new RunProjectAuditJob($scan->id))->handle(app(RunProjectAudit::class));

    $result = QualityGateResult::query()->where('scan_id', $scan->id)->first();
    expect($scan->fresh()->status->value)->toBe('failed')
        ->and($result)->not->toBeNull()
        ->and($result->outcome->value)->toBe('indeterminate');
});
