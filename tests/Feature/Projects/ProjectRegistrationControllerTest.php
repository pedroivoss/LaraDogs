<?php

namespace Tests\Feature\Projects;

use App\Models\Audit\Project;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectRegistrationControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/laradogs-project-add-'.uniqid();
        mkdir($this->root.'/Sample', recursive: true);
        config(['laradogs.projects.root' => $this->root]);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->root);
        config(['laradogs.projects.root' => '/projects']);

        parent::tearDown();
    }

    public function test_guest_cannot_reach_add_project(): void
    {
        $this->get('/projects/add')->assertRedirect('/login');
    }

    public function test_regular_user_is_denied_add_project(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/projects/add')->assertNotFound();
        $this->actingAs($user)->post('/projects', ['directory' => 'Sample'])->assertNotFound();
    }

    public function test_admin_sees_available_directories(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/projects/add')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('root_available', true)
                ->has('directories', 1)
                ->where('directories.0.name', 'Sample')
            );
    }

    public function test_admin_can_register_a_project_from_the_picker(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/projects', [
            'directory' => 'Sample',
            'name' => 'My Sample',
        ]);

        $project = Project::query()->where('name', 'My Sample')->firstOrFail();
        $response->assertRedirect("/projects/{$project->public_id}");
        $this->assertSame(realpath($this->root.'/Sample'), $project->path);
    }

    public function test_registering_the_same_directory_twice_does_not_create_a_duplicate(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/projects', ['directory' => 'Sample']);
        $this->actingAs($admin)->post('/projects', ['directory' => 'Sample']);

        $this->assertSame(1, Project::query()->count());
    }

    public function test_a_directory_name_containing_traversal_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/projects', ['directory' => '../etc']);

        $response->assertInvalid(['directory']);
        $this->assertSame(0, Project::query()->count());
    }

    public function test_no_host_path_is_ever_exposed_only_the_container_canonical_path(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/projects', ['directory' => 'Sample']);

        $project = Project::query()->firstOrFail();
        $this->assertStringNotContainsString('/Users/', $project->path);
    }

    public function test_empty_root_state_is_reported_clearly(): void
    {
        (new Filesystem)->deleteDirectory($this->root.'/Sample');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/projects/add')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('root_available', true)
                ->has('directories', 0)
            );
    }

    public function test_unmounted_root_state_is_reported_clearly(): void
    {
        config(['laradogs.projects.root' => '/this/does/not/exist']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/projects/add')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('root_available', false));
    }
}
