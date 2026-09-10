<?php

namespace Tests\Feature;

use App\Audit\Projects\RegisterProject;
use App\Models\Audit\Finding;
use App\Models\Audit\Project;
use App\Models\Audit\Scan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_dashboard_shows_a_proper_empty_state_with_no_projects()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('summary.total_projects', 0)
            ->where('summary.total_open_findings', 0)
        );
    }

    public function test_dashboard_summary_reflects_real_persisted_data_only()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $path = dirname(__DIR__).'/Fixtures/discovery/laravel-blade';
        $project = (new RegisterProject)->register($path)->project;

        Finding::query()->create([
            'project_id' => $project->id,
            'fingerprint' => str_repeat('a', 64),
            'fingerprint_version' => 'v1',
            'rule_id' => 'LARA-SEC-001',
            'analyzer_id' => 'semgrep',
            'category' => 'security',
            'severity' => 'critical',
            'confidence' => 'high',
            'title' => 'Test finding',
            'status' => 'open',
            'first_seen_scan_id' => $this->createScan($project)->id,
            'first_seen_at' => now(),
            'last_seen_scan_id' => $this->createScan($project)->id,
            'last_seen_at' => now(),
        ]);

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('summary.total_projects', 1)
            ->where('summary.total_open_findings', 1)
            ->where('summary.critical_open_findings', 1)
            ->where('summary.projects_with_open_findings', 1)
        );
    }

    private function createScan(Project $project): Scan
    {
        return Scan::query()->create([
            'project_id' => $project->id,
            'status' => 'completed',
            'started_at' => now(),
            'finished_at' => now(),
            'project_profile' => ['project' => ['type' => 'laravel']],
        ]);
    }
}
