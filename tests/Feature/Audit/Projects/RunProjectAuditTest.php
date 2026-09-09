<?php

use App\Audit\Discovery\ProjectDiscovery;
use App\Audit\Engine\AuditContext;
use App\Audit\Engine\AuditEngine;
use App\Audit\Engine\Execution\AnalyzerCoverage;
use App\Audit\Engine\Registry\AnalyzerRegistry;
use App\Audit\Findings\ActorType;
use App\Audit\Findings\FindingStatus;
use App\Audit\Findings\Fingerprint\Fingerprinter;
use App\Audit\Findings\Ingestion\FindingIngestor;
use App\Audit\Findings\Ingestion\FindingReconciler;
use App\Audit\Findings\Ingestion\ScanRecorder;
use App\Audit\Findings\Lifecycle\FindingLifecycleService;
use App\Audit\Findings\Redaction\EvidenceRedactor;
use App\Audit\Findings\ScanStatus;
use App\Audit\Projects\RegisterProject;
use App\Audit\Projects\RunProjectAudit;
use App\Audit\Projects\RunProjectAuditOutcome;
use App\Models\Audit\Finding;
use App\Models\Audit\FindingOccurrence;
use App\Models\Audit\FindingStatusHistory;
use App\Models\Audit\Project;
use App\Models\Audit\Scan;
use App\Models\Audit\ScanAnalyzerExecution;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\Support\Engine\Analyzers\AlwaysPassAnalyzer;
use Tests\Support\Engine\Analyzers\ThrowingAnalyzer;
use Tests\Support\Engine\Analyzers\TimedOutAnalyzer;
use Tests\Support\Findings\SyntheticCandidates;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Rebinds the container's AnalyzerRegistry singleton so RunProjectAudit
 * (resolved fresh via app(RunProjectAudit::class) per test) exercises the
 * given synthetic analyzers instead of the real Composer/npm/Semgrep
 * analyzers AppServiceProvider registers by default — keeping this suite
 * network-free and deterministic, matching the existing
 * ScanRecorderIntegrationTest convention of preferring real
 * Discovery+Engine+persistence over mocks, with fakes only at the
 * process-execution boundary these synthetic analyzers replace entirely.
 */
function bindRegistry(AnalyzerRegistry $registry): void
{
    App::instance(AnalyzerRegistry::class, $registry);
}

function registerFixtureProject(string $fixture = 'laravel-blade'): Project
{
    $path = dirname(__DIR__, 3).'/Fixtures/discovery/'.$fixture;
    $result = (new RegisterProject)->register($path);
    expect($result->succeeded())->toBeTrue();

    return $result->project;
}

it('runs a persisted audit end-to-end: fresh discovery, real Engine, Scan/executions/findings/occurrences all persisted', function () {
    $registry = new AnalyzerRegistry;
    $registry->register(new AlwaysPassAnalyzer('composer-security'));
    bindRegistry($registry);

    $project = registerFixtureProject();

    $result = app(RunProjectAudit::class)->run($project);

    expect($result->outcome)->toBe(RunProjectAuditOutcome::Completed)
        ->and($result->succeeded())->toBeTrue()
        ->and($result->scan)->not->toBeNull()
        ->and($result->scan->status)->toBe(ScanStatus::Completed)
        ->and($result->scan->project_id)->toBe($project->id);

    expect(Scan::query()->where('project_id', $project->id)->count())->toBe(1);
    expect(ScanAnalyzerExecution::query()->where('scan_id', $result->scan->id)->count())->toBe(1);

    // The scan-time profile snapshot is preserved, independent of the
    // project's own (unpersisted) current stack.
    expect($result->scan->project_profile['project']['type'])->toBe('laravel');
});

it('persists findings and occurrences through the existing Finding domain, never a second model', function () {
    $registry = new AnalyzerRegistry;
    $registry->register(new AlwaysPassAnalyzer('composer-security'));
    bindRegistry($registry);

    $project = registerFixtureProject();

    // A real analyzer producing candidates requires a real ProducesFindingCandidates
    // implementation; the synthetic AlwaysPassAnalyzer above doesn't implement
    // it, so we go one level down (ScanRunner directly) to inject a candidate
    // the same way a real analyzer's own candidates() call would — this
    // proves ingestion/occurrence persistence without inventing a second
    // finding pipeline.
    $recorder = new ScanRecorder(
        new FindingIngestor(new Fingerprinter, new EvidenceRedactor, new FindingLifecycleService),
        new FindingReconciler(new FindingLifecycleService),
    );
    $discovery = (new ProjectDiscovery)->discover($project->path);
    $context = new AuditContext(runId: 'run-findings', projectPath: $discovery->path, profile: $discovery->profile);
    $runResult = (new AuditEngine($registry))->run($context);

    $scan = $recorder->startScan($project, $context->profile);
    $recorder->completeScan($scan, $runResult, [
        'composer-security' => [SyntheticCandidates::sqlInjection()],
    ]);

    expect(Finding::query()->where('project_id', $project->id)->count())->toBe(1);
    $finding = Finding::query()->where('project_id', $project->id)->firstOrFail();
    expect($finding->status)->toBe(FindingStatus::Open);

    $occurrence = FindingOccurrence::query()->where('finding_id', $finding->id)->where('scan_id', $scan->id)->first();
    expect($occurrence)->not->toBeNull()
        ->and($occurrence->file_path)->toBe('app/Repositories/UserRepository.php');

    expect(FindingStatusHistory::query()->where('finding_id', $finding->id)->count())->toBe(1);
});

it('preserves scan history across multiple audits of the same project — nothing is overwritten', function () {
    $registry = new AnalyzerRegistry;
    $registry->register(new AlwaysPassAnalyzer('composer-security'));
    bindRegistry($registry);

    $project = registerFixtureProject();
    $runner = app(RunProjectAudit::class);

    $first = $runner->run($project);
    $second = $runner->run($project);
    $third = $runner->run($project);

    expect($first->scan->id)->not->toBe($second->scan->id)
        ->and($second->scan->id)->not->toBe($third->scan->id);

    expect(Scan::query()->where('project_id', $project->id)->count())->toBe(3);
});

it('refuses to start a second audit while one is already Running for the same project', function () {
    $project = registerFixtureProject();

    Scan::query()->create([
        'project_id' => $project->id,
        'status' => ScanStatus::Running,
        'started_at' => now(),
        'project_profile' => ['project' => ['type' => 'laravel']],
    ]);

    $registry = new AnalyzerRegistry;
    $registry->register(new AlwaysPassAnalyzer('composer-security'));
    bindRegistry($registry);

    $result = app(RunProjectAudit::class)->run($project);

    expect($result->outcome)->toBe(RunProjectAuditOutcome::AlreadyRunning)
        ->and($result->succeeded())->toBeFalse()
        ->and($result->conflictingScan)->not->toBeNull();

    // No new scan was created.
    expect(Scan::query()->where('project_id', $project->id)->count())->toBe(1);
});

it('fails safely, with all prior history intact, when the registered project path has disappeared', function () {
    $filesystem = new Filesystem;
    $target = sys_get_temp_dir().'/laradogs-project-disappearing-'.uniqid();
    $filesystem->copyDirectory(dirname(__DIR__, 3).'/Fixtures/discovery/laravel-blade', $target);

    $registerResult = (new RegisterProject)->register($target);
    expect($registerResult->succeeded())->toBeTrue();
    $project = $registerResult->project;

    $registry = new AnalyzerRegistry;
    $registry->register(new AlwaysPassAnalyzer('composer-security'));
    bindRegistry($registry);

    // A first, successful scan exists...
    $first = app(RunProjectAudit::class)->run($project);
    expect($first->succeeded())->toBeTrue();

    // ...then the path disappears (e.g. deleted/moved outside LaraDogs).
    $filesystem->deleteDirectory($target);

    $second = app(RunProjectAudit::class)->run($project);

    expect($second->outcome)->toBe(RunProjectAuditOutcome::PathUnavailable)
        ->and($second->succeeded())->toBeFalse()
        ->and($second->scan)->toBeNull();

    // The first scan's history is untouched.
    expect(Scan::query()->where('project_id', $project->id)->count())->toBe(1);
    expect(Scan::query()->where('project_id', $project->id)->first()->status)->toBe(ScanStatus::Completed);
});

it('records an analyzer timeout as a scan-level execution without failing the whole scan', function () {
    $registry = new AnalyzerRegistry;
    $registry->register(new TimedOutAnalyzer('slow-analyzer'));
    bindRegistry($registry);

    $project = registerFixtureProject();
    $result = app(RunProjectAudit::class)->run($project);

    expect($result->succeeded())->toBeTrue()
        ->and($result->scan->status)->toBe(ScanStatus::Completed);

    $execution = ScanAnalyzerExecution::query()->where('scan_id', $result->scan->id)->firstOrFail();
    expect($execution->status->value)->toBe('timed_out');
});

it('records an analyzer failure (thrown exception) as a scan-level execution without failing the whole scan', function () {
    $registry = new AnalyzerRegistry;
    $registry->register(new ThrowingAnalyzer('broken-analyzer'));
    bindRegistry($registry);

    $project = registerFixtureProject();
    $result = app(RunProjectAudit::class)->run($project);

    expect($result->succeeded())->toBeTrue()
        ->and($result->scan->status)->toBe(ScanStatus::Completed);

    $execution = ScanAnalyzerExecution::query()->where('scan_id', $result->scan->id)->firstOrFail();
    expect($execution->status->value)->toBe('failed');
});

// --- Reconciliation semantics exercised through the PERSISTED workflow (Phase 3.1 safety) ---

function completeFixtureScanWithCandidate(Project $project, AnalyzerRegistry $registry, string $runId, array $candidatesByAnalyzer): Scan
{
    $recorder = new ScanRecorder(
        new FindingIngestor(new Fingerprinter, new EvidenceRedactor, new FindingLifecycleService),
        new FindingReconciler(new FindingLifecycleService),
    );
    $discovery = (new ProjectDiscovery)->discover($project->path);
    $context = new AuditContext(runId: $runId, projectPath: $discovery->path, profile: $discovery->profile);
    $runResult = (new AuditEngine($registry))->run($context);

    $scan = $recorder->startScan($project, $context->profile);

    return $recorder->completeScan($scan, $runResult, $candidatesByAnalyzer);
}

it('auto-resolves a finding through the persisted project workflow once its verified analyzer stops reporting it, then regresses it if it reappears', function () {
    $project = registerFixtureProject();

    // Scan 1: finding appears -> OPEN.
    $registryOne = new AnalyzerRegistry;
    $registryOne->register(new AlwaysPassAnalyzer('composer-security', AnalyzerCoverage::explicit(['LARA-SEC-023'])));
    completeFixtureScanWithCandidate($project, $registryOne, 'scan-1', [
        'composer-security' => [SyntheticCandidates::sqlInjection()],
    ]);

    $finding = Finding::query()->where('project_id', $project->id)->firstOrFail();
    expect($finding->status)->toBe(FindingStatus::Open);

    // Scan 2: the same, verified analyzer runs clean -> RESOLVED.
    $registryTwo = new AnalyzerRegistry;
    $registryTwo->register(new AlwaysPassAnalyzer('composer-security', AnalyzerCoverage::explicit(['LARA-SEC-023'])));
    completeFixtureScanWithCandidate($project, $registryTwo, 'scan-2', []);

    $finding->refresh();
    expect($finding->status)->toBe(FindingStatus::Resolved);

    // Scan 3: the same finding appears again -> OPEN (reopened, not a new row).
    $registryThree = new AnalyzerRegistry;
    $registryThree->register(new AlwaysPassAnalyzer('composer-security', AnalyzerCoverage::explicit(['LARA-SEC-023'])));
    completeFixtureScanWithCandidate($project, $registryThree, 'scan-3', [
        'composer-security' => [SyntheticCandidates::sqlInjection()],
    ]);

    $finding->refresh();
    expect($finding->status)->toBe(FindingStatus::Open)
        ->and(Finding::query()->where('project_id', $project->id)->count())->toBe(1);

    // History shows every transition, in order.
    $transitions = FindingStatusHistory::query()
        ->where('finding_id', $finding->id)
        ->orderBy('id')
        ->pluck('new_status')
        ->map(fn ($status) => $status->value)
        ->all();

    expect($transitions)->toBe(['open', 'resolved', 'open']);
});

it('does NOT auto-resolve a finding through the persisted workflow when the only executions have Unknown coverage', function () {
    $project = registerFixtureProject();

    $registryOne = new AnalyzerRegistry;
    // No explicit AnalyzerCoverage given -> defaults to Unknown, exactly
    // like the real Composer/npm analyzers today.
    $registryOne->register(new AlwaysPassAnalyzer('composer-security'));
    completeFixtureScanWithCandidate($project, $registryOne, 'unknown-1', [
        'composer-security' => [SyntheticCandidates::sqlInjection()],
    ]);

    $finding = Finding::query()->where('project_id', $project->id)->firstOrFail();
    expect($finding->status)->toBe(FindingStatus::Open);

    $registryTwo = new AnalyzerRegistry;
    $registryTwo->register(new AlwaysPassAnalyzer('composer-security'));
    completeFixtureScanWithCandidate($project, $registryTwo, 'unknown-2', []);

    $finding->refresh();
    // Absence of the finding under Unknown coverage must NOT be read as "fixed."
    expect($finding->status)->toBe(FindingStatus::Open);
});

it('never reopens or resolves a suppressed (accepted-risk/false-positive/ignored) finding through the persisted workflow', function () {
    $project = registerFixtureProject();

    $registryOne = new AnalyzerRegistry;
    $registryOne->register(new AlwaysPassAnalyzer('composer-security', AnalyzerCoverage::explicit(['LARA-SEC-023'])));
    completeFixtureScanWithCandidate($project, $registryOne, 'suppressed-1', [
        'composer-security' => [SyntheticCandidates::sqlInjection()],
    ]);

    $finding = Finding::query()->where('project_id', $project->id)->firstOrFail();

    (new FindingLifecycleService)->transition(
        $finding,
        FindingStatus::FalsePositive,
        ActorType::User,
        actorIdentifier: 'tester',
        reason: 'Confirmed not exploitable in this context.',
    );

    // A scan where the analyzer runs clean (would auto-resolve an Open
    // finding) must NOT touch a suppressed one.
    $registryTwo = new AnalyzerRegistry;
    $registryTwo->register(new AlwaysPassAnalyzer('composer-security', AnalyzerCoverage::explicit(['LARA-SEC-023'])));
    completeFixtureScanWithCandidate($project, $registryTwo, 'suppressed-2', []);

    $finding->refresh();
    expect($finding->status)->toBe(FindingStatus::FalsePositive);

    // Nor does the SAME finding reappearing reopen it automatically.
    $registryThree = new AnalyzerRegistry;
    $registryThree->register(new AlwaysPassAnalyzer('composer-security', AnalyzerCoverage::explicit(['LARA-SEC-023'])));
    completeFixtureScanWithCandidate($project, $registryThree, 'suppressed-3', [
        'composer-security' => [SyntheticCandidates::sqlInjection()],
    ]);

    $finding->refresh();
    expect($finding->status)->toBe(FindingStatus::FalsePositive);
});

it('does not persist anything (findings/executions) when discovery fails to even run analyzers, and never executes target code', function () {
    $filesystem = new Filesystem;
    $source = dirname(__DIR__, 3).'/Fixtures/discovery/no-code-execution';
    $target = sys_get_temp_dir().'/laradogs-project-no-exec-'.uniqid();
    $filesystem->copyDirectory($source, $target);

    try {
        $project = (new RegisterProject)->register($target)->project;

        $registry = new AnalyzerRegistry;
        $registry->register(new AlwaysPassAnalyzer);
        bindRegistry($registry);

        $result = app(RunProjectAudit::class)->run($project);

        expect($result->succeeded())->toBeTrue();
        expect($filesystem->exists($target.'/SHOULD_NEVER_EXIST'))->toBeFalse();
    } finally {
        $filesystem->deleteDirectory($target);
    }
});
