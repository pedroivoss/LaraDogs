<?php

use App\Audit\Findings\ScanOrigin;
use App\Audit\Findings\ScanStatus;
use App\Audit\Projects\AuditSchedule;
use App\Audit\Projects\DispatchDueProjectAudits;
use App\Audit\Projects\RegisterProject;
use App\Jobs\RunProjectAuditJob;
use App\Models\Audit\Project;
use App\Models\Audit\ProjectActiveScan;
use App\Models\Audit\Scan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function registerDueScheduleFixtureProject(string $fixture = 'laravel-blade'): Project
{
    $path = dirname(__DIR__, 3).'/Fixtures/discovery/'.$fixture;
    $result = (new RegisterProject)->register($path);
    expect($result->succeeded())->toBeTrue();

    return $result->project;
}

it('dispatches a due project: enqueues a Scheduled scan, dispatches the queue job, and advances next_audit_at', function () {
    Bus::fake();

    $project = registerDueScheduleFixtureProject();
    $project->audit_schedule = AuditSchedule::Daily;
    $project->next_audit_at = now()->subMinute();
    $project->save();

    $dispatchedCount = app(DispatchDueProjectAudits::class)->dispatch();

    expect($dispatchedCount)->toBe(1);

    $scan = Scan::query()->where('project_id', $project->id)->firstOrFail();
    expect($scan->status)->toBe(ScanStatus::Queued)
        ->and($scan->origin)->toBe(ScanOrigin::Scheduled)
        ->and($scan->initiated_by_user_id)->toBeNull();

    Bus::assertDispatched(RunProjectAuditJob::class);

    $project->refresh();
    expect($project->next_audit_at)->not->toBeNull()
        ->and($project->next_audit_at->isFuture())->toBeTrue()
        ->and($project->last_scheduled_audit_at)->not->toBeNull();
});

it('ignores a project whose schedule is Disabled, even if next_audit_at is somehow set in the past', function () {
    Bus::fake();

    $project = registerDueScheduleFixtureProject();
    $project->audit_schedule = AuditSchedule::Disabled;
    $project->next_audit_at = now()->subMinute();
    $project->save();

    $dispatchedCount = app(DispatchDueProjectAudits::class)->dispatch();

    expect($dispatchedCount)->toBe(0);
    expect(Scan::query()->where('project_id', $project->id)->count())->toBe(0);
    Bus::assertNotDispatched(RunProjectAuditJob::class);
});

it('ignores a project that is not yet due', function () {
    Bus::fake();

    $project = registerDueScheduleFixtureProject();
    $project->audit_schedule = AuditSchedule::Daily;
    $project->next_audit_at = now()->addHour();
    $project->save();

    $dispatchedCount = app(DispatchDueProjectAudits::class)->dispatch();

    expect($dispatchedCount)->toBe(0);
    expect(Scan::query()->where('project_id', $project->id)->count())->toBe(0);
});

it('never dispatches a second audit for a project that already has an active scan', function () {
    Bus::fake();

    $project = registerDueScheduleFixtureProject();
    $project->audit_schedule = AuditSchedule::Daily;
    $project->next_audit_at = now()->subMinute();
    $project->save();

    $activeScan = Scan::query()->create([
        'project_id' => $project->id,
        'status' => ScanStatus::Running,
        'started_at' => now(),
        'project_profile' => ['project' => ['type' => 'laravel']],
    ]);
    ProjectActiveScan::query()->create(['project_id' => $project->id, 'scan_id' => $activeScan->id]);

    $dispatchedCount = app(DispatchDueProjectAudits::class)->dispatch();

    expect($dispatchedCount)->toBe(0);
    // No new scan was created — only the pre-existing active one exists.
    expect(Scan::query()->where('project_id', $project->id)->count())->toBe(1);
    Bus::assertNotDispatched(RunProjectAuditJob::class);

    // A skipped dispatch still advances next_audit_at — a busy project
    // must not get stuck retrying every single scheduler tick.
    $project->refresh();
    expect($project->next_audit_at->isFuture())->toBeTrue();
});

it('enqueues exactly one catch-up audit when several scheduled occurrences were missed while LaraDogs was down', function () {
    Bus::fake();

    $project = registerDueScheduleFixtureProject();
    $project->audit_schedule = AuditSchedule::Daily;
    // Simulate several missed days — next_audit_at is far in the past.
    $project->next_audit_at = now()->subDays(5);
    $project->save();

    $dispatchedCount = app(DispatchDueProjectAudits::class)->dispatch();

    expect($dispatchedCount)->toBe(1);
    expect(Scan::query()->where('project_id', $project->id)->count())->toBe(1);

    // The recomputed next_audit_at is based on NOW, not on the missed
    // historical occurrence — never a backlog of catch-up runs.
    $project->refresh();
    expect($project->next_audit_at->isFuture())->toBeTrue();

    // A second tick immediately after must not enqueue anything further.
    $secondDispatchedCount = app(DispatchDueProjectAudits::class)->dispatch();
    expect($secondDispatchedCount)->toBe(0);
    expect(Scan::query()->where('project_id', $project->id)->count())->toBe(1);
});
