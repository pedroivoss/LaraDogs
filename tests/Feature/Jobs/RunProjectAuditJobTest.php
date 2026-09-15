<?php

use App\Audit\Engine\Registry\AnalyzerRegistry;
use App\Audit\Findings\ScanOrigin;
use App\Audit\Findings\ScanStatus;
use App\Audit\Projects\RegisterProject;
use App\Audit\Projects\RunProjectAudit;
use App\Jobs\RunProjectAuditJob;
use App\Models\Audit\Project;
use App\Models\Audit\ProjectActiveScan;
use App\Models\Audit\Scan;
use App\Models\Audit\ScanAnalyzerExecution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Engine\Analyzers\AlwaysPassAnalyzer;
use Tests\Support\Engine\Analyzers\ThrowingAnalyzer;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function registerJobFixtureProject(string $fixture = 'laravel-blade'): Project
{
    $path = dirname(__DIR__, 2).'/Fixtures/discovery/'.$fixture;
    $result = (new RegisterProject)->register($path);
    expect($result->succeeded())->toBeTrue();

    return $result->project;
}

it('runs a REAL database queue job end-to-end: Queued -> Running -> Completed, with valid persisted structure', function () {
    config(['queue.default' => 'database']);

    $registry = new AnalyzerRegistry;
    $registry->register(new AlwaysPassAnalyzer('composer-security'));
    App::instance(AnalyzerRegistry::class, $registry);

    $project = registerJobFixtureProject();
    $user = User::factory()->admin()->create();

    $enqueueResult = app(RunProjectAudit::class)->enqueue($project, ScanOrigin::Manual, $user);
    $scan = $enqueueResult->scan;

    expect($scan)->not->toBeNull()
        ->and($scan->status)->toBe(ScanStatus::Queued);

    // A real row landed in the `jobs` table — the Dashboard's "Queued"
    // feedback does not depend on a worker having started yet.
    RunProjectAuditJob::dispatch($scan->id);
    expect(DB::table('jobs')->count())->toBe(1);

    // A real worker, processing the real `database` queue connection —
    // not a direct handle() call — proving the whole dispatch->pop->
    // execute path actually works, not just the job class in isolation.
    Artisan::call('queue:work', [
        '--once' => true,
        '--queue' => 'default',
    ]);

    expect(DB::table('jobs')->count())->toBe(0);

    $scan->refresh();
    expect($scan->status)->toBe(ScanStatus::Completed)
        ->and($scan->origin)->toBe(ScanOrigin::Manual)
        ->and($scan->initiated_by_user_id)->toBe($user->id)
        ->and($scan->finished_at)->not->toBeNull();

    expect(ScanAnalyzerExecution::query()->where('scan_id', $scan->id)->count())->toBe(1);
});

it('marks the scan Failed (never Completed, never left Queued/Running) when the job itself throws', function () {
    $registry = new AnalyzerRegistry;
    $registry->register(new ThrowingAnalyzer('broken-analyzer'));
    App::instance(AnalyzerRegistry::class, $registry);

    $project = registerJobFixtureProject();
    $enqueueResult = app(RunProjectAudit::class)->enqueue($project, ScanOrigin::Manual);
    $scan = $enqueueResult->scan;

    // ThrowingAnalyzer is recorded as a scan-level execution failure by
    // the Engine itself (see RunProjectAuditTest's own equivalent case) —
    // to exercise the job's OWN defensive `failed()` hook instead, we
    // simulate a worker crash by invoking it directly.
    $job = new RunProjectAuditJob($scan->id);
    $job->failed(new RuntimeException('Simulated worker crash mid-execution.'));

    $scan->refresh();
    expect($scan->status)->toBe(ScanStatus::Failed)
        ->and($scan->finished_at)->not->toBeNull();

    // The mutex row was released — a new audit can start.
    expect(ProjectActiveScan::query()->find($project->id))->toBeNull();
});

it('no-ops when handle() is called for a scan that is no longer Queued (already reclaimed or completed)', function () {
    $registry = new AnalyzerRegistry;
    $registry->register(new AlwaysPassAnalyzer('composer-security'));
    App::instance(AnalyzerRegistry::class, $registry);

    $project = registerJobFixtureProject();
    $enqueueResult = app(RunProjectAudit::class)->enqueue($project, ScanOrigin::Manual);
    $scan = $enqueueResult->scan;

    // Simulate the scan having already been completed by the time this
    // (duplicate/stale) job payload is picked up.
    $scan->status = ScanStatus::Completed;
    $scan->finished_at = now();
    $scan->save();

    $job = new RunProjectAuditJob($scan->id);
    $job->handle(app(RunProjectAudit::class));

    // Untouched — handle() must never re-run or overwrite a scan that
    // has already left the Queued state.
    $scan->refresh();
    expect($scan->status)->toBe(ScanStatus::Completed);
});

it('no-ops when handle() is called for a scan id that no longer exists', function () {
    $job = new RunProjectAuditJob(999999);

    $job->handle(app(RunProjectAudit::class));

    expect(Scan::query()->count())->toBe(0);
});
