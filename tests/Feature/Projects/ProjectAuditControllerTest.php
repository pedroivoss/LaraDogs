<?php

namespace Tests\Feature\Projects;

use App\Audit\Engine\Registry\AnalyzerRegistry;
use App\Audit\Findings\ScanOrigin;
use App\Audit\Findings\ScanStatus;
use App\Audit\Projects\RegisterProject;
use App\Audit\Projects\RunProjectAudit;
use App\Jobs\RunProjectAuditJob;
use App\Models\Audit\Project;
use App\Models\Audit\Scan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Support\Engine\Analyzers\AlwaysPassAnalyzer;
use Tests\TestCase;

class ProjectAuditControllerTest extends TestCase
{
    use RefreshDatabase;

    private function registerFixtureProject(string $fixture = 'laravel-blade'): Project
    {
        $path = dirname(__DIR__, 2).'/Fixtures/discovery/'.$fixture;

        return (new RegisterProject)->register($path)->project;
    }

    public function test_guest_cannot_trigger_an_audit(): void
    {
        $project = $this->registerFixtureProject();

        $this->post(route('projects.audits.store', $project))->assertRedirect('/login');
    }

    public function test_regular_user_is_denied_triggering_an_audit(): void
    {
        $user = User::factory()->create();
        $project = $this->registerFixtureProject();

        $this->actingAs($user)
            ->post(route('projects.audits.store', $project))
            ->assertNotFound();

        expect(Scan::query()->count())->toBe(0);
    }

    public function test_owner_can_trigger_a_manual_audit_and_gets_a_fast_queued_response(): void
    {
        Bus::fake();

        $owner = User::factory()->owner()->create();
        $project = $this->registerFixtureProject();

        $response = $this->actingAs($owner)->post(route('projects.audits.store', $project));

        $response->assertRedirect();

        $scan = Scan::query()->where('project_id', $project->id)->firstOrFail();
        expect($scan->status)->toBe(ScanStatus::Queued)
            ->and($scan->origin)->toBe(ScanOrigin::Manual)
            ->and($scan->initiated_by_user_id)->toBe($owner->id);

        // Analyzers never run inside the request — the job is dispatched,
        // not executed synchronously.
        Bus::assertDispatched(RunProjectAuditJob::class);
    }

    public function test_admin_can_also_trigger_a_manual_audit(): void
    {
        Bus::fake();

        $admin = User::factory()->admin()->create();
        $project = $this->registerFixtureProject();

        $this->actingAs($admin)->post(route('projects.audits.store', $project))->assertRedirect();

        expect(Scan::query()->where('project_id', $project->id)->count())->toBe(1);
    }

    public function test_two_rapid_requests_for_the_same_project_produce_exactly_one_active_audit(): void
    {
        Bus::fake();

        $owner = User::factory()->owner()->create();
        $project = $this->registerFixtureProject();

        $this->actingAs($owner)->post(route('projects.audits.store', $project))->assertRedirect();
        $this->actingAs($owner)->post(route('projects.audits.store', $project))->assertRedirect();

        // Only ONE scan was reserved; the second request found the mutex
        // already held and declined instead of enqueuing a duplicate.
        expect(Scan::query()->where('project_id', $project->id)->count())->toBe(1);
        Bus::assertDispatchedTimes(RunProjectAuditJob::class, 1);
    }

    public function test_the_dashboard_reflects_the_active_scan_immediately_after_queuing(): void
    {
        Bus::fake();

        $owner = User::factory()->owner()->create();
        $project = $this->registerFixtureProject();

        $this->actingAs($owner)->post(route('projects.audits.store', $project));

        $response = $this->actingAs($owner)->get(route('projects.show', $project));

        $response->assertInertia(fn ($page) => $page
            ->where('active_scan.status', 'queued')
            ->where('can_manage_audits', true)
        );
    }

    public function test_a_regular_user_sees_no_manage_audits_capability_on_the_project_page(): void
    {
        $user = User::factory()->create();
        $project = $this->registerFixtureProject();

        $response = $this->actingAs($user)->get(route('projects.show', $project));

        $response->assertInertia(fn ($page) => $page->where('can_manage_audits', false));
    }

    public function test_completed_manual_audit_appears_in_scan_history_with_its_origin(): void
    {
        $registry = new AnalyzerRegistry;
        $registry->register(new AlwaysPassAnalyzer('composer-security'));
        app()->instance(AnalyzerRegistry::class, $registry);

        $owner = User::factory()->owner()->create();
        $project = $this->registerFixtureProject();

        app(RunProjectAudit::class)->run($project, ScanOrigin::Manual, $owner);

        $response = $this->actingAs($owner)->get(route('projects.show', $project));

        $response->assertInertia(fn ($page) => $page
            ->has('recent_scans', 1)
            ->where('recent_scans.0.origin', 'manual')
        );
    }
}
