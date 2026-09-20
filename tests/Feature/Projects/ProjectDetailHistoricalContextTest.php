<?php

namespace Tests\Feature\Projects;

use App\Audit\Discovery\ProjectDiscovery;
use App\Audit\Engine\Registry\AnalyzerRegistry;
use App\Audit\Findings\Ingestion\ScanRecorder;
use App\Audit\Findings\ScanOrigin;
use App\Audit\Findings\ScanStatus;
use App\Audit\Projects\RegisterProject;
use App\Audit\Projects\RunProjectAudit;
use App\Models\Audit\Project;
use App\Models\Audit\Scan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Engine\Analyzers\AlwaysPassAnalyzer;
use Tests\TestCase;

/**
 * Phase 7.1.4.1: an ACTIVE (Queued/Running) scan must never replace the
 * latest TERMINAL scan as the source of Project Detail's historical
 * context (analyzer status, profile snapshot, "last audit").
 */
class ProjectDetailHistoricalContextTest extends TestCase
{
    use RefreshDatabase;

    private function project(): Project
    {
        $registry = new AnalyzerRegistry;
        $registry->register(new AlwaysPassAnalyzer('composer-security'));
        app()->instance(AnalyzerRegistry::class, $registry);

        $path = dirname(__DIR__, 2).'/Fixtures/discovery/laravel-blade';

        return (new RegisterProject)->register($path)->project;
    }

    private function completedScan(Project $project): Scan
    {
        $result = app(RunProjectAudit::class)->run($project);
        $this->assertSame(ScanStatus::Completed, $result->scan->status);

        return $result->scan;
    }

    private function queuedScan(Project $project): Scan
    {
        return app(RunProjectAudit::class)->enqueue($project, ScanOrigin::Manual)->scan;
    }

    private function runningScan(Project $project): Scan
    {
        $scan = $this->queuedScan($project);
        $profile = (new ProjectDiscovery)->discover($project->path)->profile;

        return app(ScanRecorder::class)->beginRunning($scan, $profile);
    }

    private function show(Project $project)
    {
        return $this->actingAs(User::factory()->create())->get(route('projects.show', $project));
    }

    public function test_a_project_with_no_scans_has_no_historical_context(): void
    {
        $project = $this->project();

        $this->show($project)->assertInertia(fn ($page) => $page
            ->where('active_scan', null)
            ->where('analyzer_scan', null)
            ->where('analyzer_executions', [])
            ->where('profile', null)
            ->where('summary.last_scan', null)
        );
    }

    public function test_a_completed_scan_alone_is_the_historical_context(): void
    {
        $project = $this->project();
        $completed = $this->completedScan($project);

        $this->show($project)->assertInertia(fn ($page) => $page
            ->where('active_scan', null)
            ->where('analyzer_scan.id', $completed->public_id)
            ->has('analyzer_executions', 1)
            ->where('profile.project.type', 'laravel')
            ->where('summary.last_scan.id', $completed->public_id)
            ->where('summary.last_scan.status', 'completed')
        );
    }

    public function test_a_queued_scan_is_active_and_does_not_replace_the_completed_scan_as_context(): void
    {
        $project = $this->project();
        $completed = $this->completedScan($project);
        $queued = $this->queuedScan($project);

        $this->show($project)->assertInertia(fn ($page) => $page
            ->where('active_scan.id', $queued->public_id)
            ->where('active_scan.status', 'queued')
            // The historical context is still the completed scan's.
            ->where('analyzer_scan.id', $completed->public_id)
            ->has('analyzer_executions', 1)
            ->where('summary.last_scan.id', $completed->public_id)
            ->where('summary.last_scan.status', 'completed')
            ->where('summary.last_completed_scan_analyzer_statuses', ['composer-security' => 'passed'])
        );
    }

    public function test_a_running_scan_is_active_and_does_not_replace_the_completed_scan_as_context(): void
    {
        $project = $this->project();
        $completed = $this->completedScan($project);
        $running = $this->runningScan($project);

        $this->show($project)->assertInertia(fn ($page) => $page
            ->where('active_scan.id', $running->public_id)
            ->where('active_scan.status', 'running')
            ->where('analyzer_scan.id', $completed->public_id)
            ->has('analyzer_executions', 1)
            ->where('summary.last_scan.id', $completed->public_id)
        );
    }

    public function test_an_active_scan_does_not_erase_the_framework_profile_snapshot(): void
    {
        $project = $this->project();
        $this->completedScan($project);
        $queued = $this->queuedScan($project);

        // The Queued scan's own profile is the intentionally empty placeholder...
        $this->assertSame([], $queued->fresh()->project_profile);

        // ...and must not be what Project Detail shows.
        $this->show($project)->assertInertia(fn ($page) => $page
            ->where('profile.project.type', 'laravel')
            ->has('profile.backend.laravel')
        );
    }

    public function test_the_never_scanned_condition_is_not_produced_when_history_exists(): void
    {
        $project = $this->project();
        $this->completedScan($project);
        $this->queuedScan($project);

        // The UI's "not scanned yet" branch requires: no analyzer executions,
        // no active scan and no terminal scan. With history, none of those hold.
        $this->show($project)->assertInertia(fn ($page) => $page
            ->has('analyzer_executions', 1)
            ->whereNot('analyzer_scan', null)
            ->whereNot('summary.last_scan', null)
        );
    }

    public function test_the_first_ever_audit_in_progress_has_an_active_scan_but_no_fabricated_history(): void
    {
        $project = $this->project();
        $queued = $this->queuedScan($project);

        $this->show($project)->assertInertia(fn ($page) => $page
            ->where('active_scan.id', $queued->public_id)
            ->where('analyzer_scan', null)
            ->where('analyzer_executions', [])
            ->where('summary.last_scan', null)
            ->where('profile', null)
        );
    }

    public function test_completing_the_active_scan_promotes_it_to_the_new_historical_context(): void
    {
        $project = $this->project();
        $first = $this->completedScan($project);
        $active = $this->queuedScan($project);

        $this->show($project)->assertInertia(fn ($page) => $page
            ->where('active_scan.id', $active->public_id)
            ->where('analyzer_scan.id', $first->public_id)
        );

        // What the worker does; the next poll then sees a terminal scan.
        app(RunProjectAudit::class)->execute($active->fresh());

        $this->show($project)->assertInertia(fn ($page) => $page
            ->where('active_scan', null)
            ->where('analyzer_scan.id', $active->public_id)
            ->where('summary.last_scan.id', $active->public_id)
            ->has('analyzer_executions', 1)
        );
    }

    public function test_a_failed_latest_audit_is_surfaced_as_failed_and_analyzer_results_come_from_the_last_completed_one(): void
    {
        $project = $this->project();
        $completed = $this->completedScan($project);
        $failed = app(ScanRecorder::class)->failScan($this->queuedScan($project));

        $this->show($project)->assertInertia(fn ($page) => $page
            ->where('summary.last_scan.id', $failed->public_id)
            ->where('summary.last_scan.status', 'failed')
            ->where('analyzer_scan.id', $completed->public_id)
            ->has('analyzer_executions', 1)
        );
    }

    public function test_a_project_whose_only_audit_failed_has_no_analyzer_results_and_is_not_presented_as_clean(): void
    {
        $project = $this->project();
        $failed = app(ScanRecorder::class)->failScan($this->queuedScan($project));

        $this->show($project)->assertInertia(fn ($page) => $page
            ->where('summary.last_scan.id', $failed->public_id)
            ->where('summary.last_scan.status', 'failed')
            ->where('analyzer_scan', null)
            ->where('analyzer_executions', [])
        );
    }

    public function test_the_profile_comes_from_the_newest_valid_snapshot_skipping_empty_placeholders(): void
    {
        $project = $this->project();
        $this->completedScan($project);
        // A newer terminal scan that failed before discovery: placeholder profile only.
        app(ScanRecorder::class)->failScan($this->queuedScan($project));

        $this->show($project)->assertInertia(fn ($page) => $page
            ->where('profile.project.type', 'laravel')
        );
    }

    public function test_a_completed_audit_with_no_executions_is_a_completed_context_not_a_failed_one(): void
    {
        $project = $this->project();
        $completed = Scan::query()->create([
            'project_id' => $project->id,
            'status' => ScanStatus::Completed,
            'origin' => ScanOrigin::Cli,
            'started_at' => now(),
            'finished_at' => now(),
            'project_profile' => ['project' => ['type' => 'laravel']],
        ]);

        // The UI must not describe this as a failed audit: analyzer_scan is
        // present (completed) while there are simply no executions to list.
        $this->show($project)->assertInertia(fn ($page) => $page
            ->where('analyzer_scan.id', $completed->public_id)
            ->where('analyzer_scan.status', 'completed')
            ->where('summary.last_scan.status', 'completed')
            ->where('analyzer_executions', [])
        );
    }

    public function test_only_terminal_statuses_can_provide_historical_context(): void
    {
        $terminal = collect(ScanStatus::cases())
            ->filter(fn (ScanStatus $status) => in_array($status, [ScanStatus::Completed, ScanStatus::Failed], true))
            ->map(fn (ScanStatus $status) => $status->value)
            ->values()
            ->all();

        // Guard: a future ScanStatus case must be a conscious decision here.
        $this->assertSame(['completed', 'failed'], $terminal);
        $this->assertCount(4, ScanStatus::cases());
    }
}
