<?php

namespace Tests\Feature;

use App\Audit\Findings\ActorType;
use App\Audit\Findings\FindingStatus;
use App\Audit\Findings\Lifecycle\FindingLifecycleService;
use App\Audit\Projects\RegisterProject;
use App\Models\Audit\Finding;
use App\Models\Audit\FindingStatusHistory;
use App\Models\Audit\Project;
use App\Models\Audit\Scan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FindingsControllerTest extends TestCase
{
    use RefreshDatabase;

    private function registerFixtureProject(string $fixture = 'laravel-blade'): Project
    {
        $path = dirname(__DIR__).'/Fixtures/discovery/'.$fixture;

        return (new RegisterProject)->register($path)->project;
    }

    private function createFinding(Project $project, FindingStatus $status = FindingStatus::Open): Finding
    {
        $scan = Scan::query()->create([
            'project_id' => $project->id,
            'status' => 'completed',
            'started_at' => now(),
            'finished_at' => now(),
            'project_profile' => ['project' => ['type' => 'laravel']],
        ]);

        return Finding::query()->create([
            'project_id' => $project->id,
            'fingerprint' => hash('sha256', uniqid()),
            'fingerprint_version' => 'v1',
            'rule_id' => 'LARA-SEC-001',
            'analyzer_id' => 'semgrep',
            'category' => 'security',
            'severity' => 'high',
            'confidence' => 'medium',
            'title' => 'Test finding',
            'status' => $status,
            'first_seen_scan_id' => $scan->id,
            'first_seen_at' => now(),
            'last_seen_scan_id' => $scan->id,
            'last_seen_at' => now(),
        ]);
    }

    public function test_guests_are_redirected_from_finding_detail()
    {
        $project = $this->registerFixtureProject();
        $finding = $this->createFinding($project);

        $this->get(route('findings.show', $finding))->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_view_finding_detail_with_full_evidence()
    {
        $user = User::factory()->create();
        $project = $this->registerFixtureProject();
        $finding = $this->createFinding($project);

        $response = $this->actingAs($user)->get(route('findings.show', $finding));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('finding.id', $finding->public_id)
            ->where('finding.rule_id', 'LARA-SEC-001')
            ->where('finding.project.id', $project->public_id)
            ->has('occurrences')
            ->has('status_history')
        );
    }

    public function test_finding_detail_uses_the_public_ulid_not_the_numeric_id()
    {
        $project = $this->registerFixtureProject();
        $finding = $this->createFinding($project);

        $url = route('findings.show', $finding);

        $this->assertSame(url("/findings/{$finding->public_id}"), $url);
    }

    public function test_guests_cannot_mutate_a_finding_status()
    {
        $project = $this->registerFixtureProject();
        $finding = $this->createFinding($project);

        $response = $this->patch(route('findings.status', $finding), ['status' => 'resolved']);

        $response->assertRedirect(route('login'));
        $this->assertSame(FindingStatus::Open, $finding->fresh()->status);
    }

    public function test_authenticated_user_can_transition_a_finding_status_through_the_lifecycle_service()
    {
        $user = User::factory()->create();
        $project = $this->registerFixtureProject();
        $finding = $this->createFinding($project);

        $response = $this->actingAs($user)->patch(route('findings.status', $finding), [
            'status' => 'confirmed',
        ]);

        $response->assertRedirect();
        $finding->refresh();
        $this->assertSame(FindingStatus::Confirmed, $finding->status);

        $history = FindingStatusHistory::query()->where('finding_id', $finding->id)->latest('id')->first();
        $this->assertSame(ActorType::User, $history->actor_type);
        $this->assertSame($user->email, $history->actor_identifier);
    }

    public function test_transitioning_to_a_status_requiring_a_reason_without_one_fails_validation_server_side()
    {
        $user = User::factory()->create();
        $project = $this->registerFixtureProject();
        $finding = $this->createFinding($project);

        $response = $this->actingAs($user)->patch(route('findings.status', $finding), [
            'status' => 'false_positive',
            // no reason given
        ]);

        $response->assertSessionHasErrors('reason');
        $this->assertSame(FindingStatus::Open, $finding->fresh()->status);
    }

    public function test_transitioning_to_a_status_requiring_a_reason_with_one_succeeds()
    {
        $user = User::factory()->create();
        $project = $this->registerFixtureProject();
        $finding = $this->createFinding($project);

        $response = $this->actingAs($user)->patch(route('findings.status', $finding), [
            'status' => 'false_positive',
            'reason' => 'Confirmed not exploitable in this context.',
        ]);

        $response->assertRedirect();
        $finding->refresh();
        $this->assertSame(FindingStatus::FalsePositive, $finding->status);
        $this->assertSame('Confirmed not exploitable in this context.', $finding->status_reason);
    }

    public function test_invalid_status_value_is_rejected_by_request_validation()
    {
        $user = User::factory()->create();
        $project = $this->registerFixtureProject();
        $finding = $this->createFinding($project);

        $response = $this->actingAs($user)->patch(route('findings.status', $finding), [
            'status' => 'not-a-real-status',
        ]);

        $response->assertSessionHasErrors('status');
        $this->assertSame(FindingStatus::Open, $finding->fresh()->status);
    }

    public function test_a_suppressed_finding_status_is_preserved_and_visible_in_history()
    {
        $user = User::factory()->create();
        $project = $this->registerFixtureProject();
        $finding = $this->createFinding($project);

        (new FindingLifecycleService)->transition(
            $finding,
            FindingStatus::AcceptedRisk,
            ActorType::User,
            actorIdentifier: 'someone@example.test',
            reason: 'Accepted for this release.',
        );

        $response = $this->actingAs($user)->get(route('findings.show', $finding));

        $response->assertInertia(fn ($page) => $page
            ->where('finding.status', 'accepted_risk')
            ->where('finding.status_reason', 'Accepted for this release.')
            ->has('status_history', 1)
            ->where('status_history.0.new_status', 'accepted_risk')
        );
    }
}
