<?php

namespace Tests\Feature\Projects;

use App\Audit\Projects\AuditSchedule;
use App\Audit\Projects\RegisterProject;
use App\Models\Audit\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectAuditScheduleControllerTest extends TestCase
{
    use RefreshDatabase;

    private function registerFixtureProject(string $fixture = 'laravel-blade'): Project
    {
        $path = dirname(__DIR__, 2).'/Fixtures/discovery/'.$fixture;

        return (new RegisterProject)->register($path)->project;
    }

    public function test_a_newly_registered_project_defaults_to_a_disabled_schedule(): void
    {
        $project = $this->registerFixtureProject();

        // Read back fresh from the database — proving the DEFAULT is
        // truly Disabled at rest, not merely an in-memory default that
        // was never actually persisted.
        $fromDatabase = Project::query()->findOrFail($project->id);

        expect($fromDatabase->audit_schedule)->toBe(AuditSchedule::Disabled)
            ->and($fromDatabase->next_audit_at)->toBeNull();
    }

    public function test_guest_cannot_update_the_schedule(): void
    {
        $project = $this->registerFixtureProject();

        $this->put(route('projects.audit-schedule.update', $project), ['audit_schedule' => 'daily'])
            ->assertRedirect('/login');
    }

    public function test_regular_user_is_denied_updating_the_schedule(): void
    {
        $user = User::factory()->create();
        $project = $this->registerFixtureProject();

        $this->actingAs($user)
            ->put(route('projects.audit-schedule.update', $project), ['audit_schedule' => 'daily'])
            ->assertNotFound();

        $project->refresh();
        expect($project->audit_schedule)->toBe(AuditSchedule::Disabled);
    }

    public function test_regular_user_can_still_view_the_schedule_on_the_project_page(): void
    {
        $user = User::factory()->create();
        $project = $this->registerFixtureProject();

        $response = $this->actingAs($user)->get(route('projects.show', $project));

        $response->assertInertia(fn ($page) => $page->where('schedule.audit_schedule', 'disabled'));
    }

    public function test_owner_can_enable_a_daily_schedule_and_next_audit_at_is_computed(): void
    {
        $owner = User::factory()->owner()->create();
        $project = $this->registerFixtureProject();

        $this->actingAs($owner)
            ->put(route('projects.audit-schedule.update', $project), ['audit_schedule' => 'daily'])
            ->assertRedirect();

        $project->refresh();
        expect($project->audit_schedule)->toBe(AuditSchedule::Daily)
            ->and($project->next_audit_at)->not->toBeNull()
            ->and($project->next_audit_at->isFuture())->toBeTrue();
    }

    public function test_admin_can_enable_a_weekly_schedule_with_a_day_of_week(): void
    {
        $admin = User::factory()->admin()->create();
        $project = $this->registerFixtureProject();

        $this->actingAs($admin)
            ->put(route('projects.audit-schedule.update', $project), [
                'audit_schedule' => 'weekly',
                'audit_schedule_day_of_week' => 3,
            ])
            ->assertRedirect();

        $project->refresh();
        expect($project->audit_schedule)->toBe(AuditSchedule::Weekly)
            ->and($project->audit_schedule_day_of_week)->toBe(3)
            ->and($project->next_audit_at)->not->toBeNull();
    }

    public function test_monthly_schedule_requires_a_day_of_month(): void
    {
        $owner = User::factory()->owner()->create();
        $project = $this->registerFixtureProject();

        $this->actingAs($owner)
            ->put(route('projects.audit-schedule.update', $project), ['audit_schedule' => 'monthly'])
            ->assertSessionHasErrors('audit_schedule_day_of_month');

        $project->refresh();
        expect($project->audit_schedule)->toBe(AuditSchedule::Disabled);
    }

    public function test_owner_can_disable_a_previously_enabled_schedule(): void
    {
        $owner = User::factory()->owner()->create();
        $project = $this->registerFixtureProject();

        $this->actingAs($owner)->put(route('projects.audit-schedule.update', $project), ['audit_schedule' => 'daily']);

        $this->actingAs($owner)
            ->put(route('projects.audit-schedule.update', $project), ['audit_schedule' => 'disabled'])
            ->assertRedirect();

        $project->refresh();
        expect($project->audit_schedule)->toBe(AuditSchedule::Disabled)
            ->and($project->next_audit_at)->toBeNull();
    }
}
