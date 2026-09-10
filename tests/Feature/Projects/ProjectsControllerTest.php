<?php

namespace Tests\Feature\Projects;

use App\Audit\Engine\Execution\AnalyzerCoverage;
use App\Audit\Engine\Registry\AnalyzerRegistry;
use App\Audit\Projects\RegisterProject;
use App\Audit\Projects\RunProjectAudit;
use App\Models\Audit\Project;
use App\Models\Audit\Scan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Engine\Analyzers\AlwaysPassAnalyzer;
use Tests\TestCase;

class ProjectsControllerTest extends TestCase
{
    use RefreshDatabase;

    private function registerFixtureProject(string $fixture = 'laravel-blade'): Project
    {
        $path = dirname(__DIR__, 2).'/Fixtures/discovery/'.$fixture;

        return (new RegisterProject)->register($path)->project;
    }

    public function test_guests_are_redirected_from_the_projects_index()
    {
        $this->get(route('projects.index'))->assertRedirect(route('login'));
    }

    public function test_guests_are_redirected_from_a_project_detail_page()
    {
        $project = $this->registerFixtureProject();

        $this->get(route('projects.show', $project))->assertRedirect(route('login'));
    }

    public function test_authenticated_users_see_real_persisted_projects_on_the_index()
    {
        $user = User::factory()->create();
        $projectOne = $this->registerFixtureProject('laravel-blade');
        $projectTwo = $this->registerFixtureProject('laravel-api');

        $response = $this->actingAs($user)->get(route('projects.index'));

        $response->assertOk();
        // ProjectListQuery orders by name — 'laravel-api' sorts before
        // 'laravel-blade', not registration order.
        $response->assertInertia(fn ($page) => $page
            ->has('projects', 2)
            ->where('projects.0.id', $projectTwo->public_id)
            ->where('projects.1.id', $projectOne->public_id)
        );
    }

    public function test_projects_index_uses_the_projects_public_ulid_in_the_route_not_the_numeric_id()
    {
        $user = User::factory()->create();
        $project = $this->registerFixtureProject();

        $url = route('projects.show', $project);
        $numericIdUrl = url("/projects/{$project->id}");

        $this->assertSame(url("/projects/{$project->public_id}"), $url);
        $this->assertNotSame($numericIdUrl, $url);

        $this->actingAs($user)->get($url)->assertOk();
    }

    public function test_unknown_project_id_404s()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/projects/not-a-real-id')->assertNotFound();
    }

    public function test_project_detail_shows_the_summary_computed_by_the_existing_query_layer()
    {
        $user = User::factory()->create();
        $project = $this->registerFixtureProject();

        $registry = new AnalyzerRegistry;
        $registry->register(new AlwaysPassAnalyzer('composer-security', AnalyzerCoverage::explicit(['LARA-SEC-023'])));
        app()->instance(AnalyzerRegistry::class, $registry);

        app(RunProjectAudit::class)->run($project);

        $response = $this->actingAs($user)->get(route('projects.show', $project));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('project.id', $project->public_id)
            ->has('summary')
            ->has('analyzer_executions', 1)
            ->where('audit_command', "php artisan laradogs:project:audit {$project->public_id}")
        );
    }

    public function test_project_list_does_not_issue_one_query_per_project()
    {
        $user = User::factory()->create();
        $this->registerFixtureProject('laravel-blade');
        $this->registerFixtureProject('laravel-api');

        $this->actingAs($user);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $this->get(route('projects.index'))->assertOk();

        // A small, fixed number of queries regardless of project count —
        // never proportional to the number of projects (no N+1).
        $this->assertLessThan(10, $queryCount);
    }

    public function test_scan_detail_404s_when_the_scan_does_not_belong_to_the_project_in_the_url()
    {
        $user = User::factory()->create();
        $projectOne = $this->registerFixtureProject('laravel-blade');
        $projectTwo = $this->registerFixtureProject('laravel-api');

        $scan = Scan::query()->create([
            'project_id' => $projectTwo->id,
            'status' => 'completed',
            'started_at' => now(),
            'finished_at' => now(),
            'project_profile' => ['project' => ['type' => 'laravel']],
        ]);

        $this->actingAs($user)
            ->get(route('projects.scans.show', ['project' => $projectOne, 'scan' => $scan]))
            ->assertNotFound();
    }
}
