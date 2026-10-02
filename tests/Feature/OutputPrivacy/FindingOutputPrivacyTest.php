<?php

namespace Tests\Feature\OutputPrivacy;

use App\Audit\Findings\ActorType;
use App\Audit\Findings\FindingStatus;
use App\Audit\Findings\Lifecycle\FindingLifecycleService;
use App\Audit\Projects\RegisterProject;
use App\Mcp\Tools\GetFinding;
use App\Mcp\Tools\GetFindingRemediation;
use App\Models\Audit\Finding;
use App\Models\Audit\FindingStatusHistory;
use App\Models\Audit\Project;
use App\Models\Audit\Scan;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Mcp\McpWorld;
use Tests\TestCase;

/*
 * Phase 12.1 — output/privacy hardening: (A) the raw, unvalidated
 * `finding.references` must never reach the Dashboard payload, and (B) an
 * Owner's identity must never leak through finding status history to anyone
 * but the Owner, while an ordinary user's/admin's identity remains visible
 * exactly as before (only the Owner is special-cased — there is exactly one).
 */
class FindingOutputPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function project(): Project
    {
        return (new RegisterProject)->register(dirname(__DIR__, 2).'/Fixtures/discovery/laravel-blade')->project;
    }

    private function finding(Project $project, array $over = []): Finding
    {
        $scan = Scan::query()->create([
            'project_id' => $project->id,
            'status' => 'completed',
            'started_at' => now(),
            'finished_at' => now(),
            'project_profile' => ['project' => ['type' => 'laravel']],
        ]);

        return Finding::query()->create([...[
            'project_id' => $project->id,
            'fingerprint' => hash('sha256', uniqid('', true)),
            'fingerprint_version' => 'v1',
            'rule_id' => 'laradogs.security.sql.tainted-raw-query',
            'analyzer_id' => 'semgrep',
            'category' => 'security',
            'severity' => 'high',
            'confidence' => 'medium',
            'title' => 'Test finding',
            'status' => FindingStatus::Open,
            'first_seen_scan_id' => $scan->id,
            'first_seen_at' => now(),
            'last_seen_scan_id' => $scan->id,
            'last_seen_at' => now(),
        ], ...$over]);
    }

    private function history(Finding $finding, ActorType $actorType, ?string $identifier): FindingStatusHistory
    {
        return FindingStatusHistory::query()->create([
            'finding_id' => $finding->id,
            'previous_status' => FindingStatus::Open,
            'new_status' => FindingStatus::Confirmed,
            'actor_type' => $actorType,
            'actor_identifier' => $identifier,
        ]);
    }

    // ---------------- (A) raw references never reach the client ----------------

    public function test_raw_unsafe_references_are_absent_from_the_inertia_payload()
    {
        $project = $this->project();
        $finding = $this->finding($project, ['references' => [
            'https://example.com/advisory',
            'javascript:alert(1)',
            'data:text/html,<script>1</script>',
            'file:///etc/passwd',
            'https://user:secret@example.com/foo',
            "https://example.com/\x07bad",
        ]]);

        $response = $this->actingAs(User::factory()->create())->get(route('findings.show', $finding))->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertArrayNotHasKey('references', $props['finding'], 'the raw, unvalidated references must not be sent at all');

        $safe = $props['remediation']['references'];
        $this->assertSame([['url' => 'https://example.com/advisory']], $safe, 'only the SafeReference-normalized set is delivered, via the remediation plan');

        $json = json_encode($props);
        $this->assertStringNotContainsString('javascript:', $json);
        $this->assertStringNotContainsString('data:text/html', $json);
        $this->assertStringNotContainsString('file:///etc/passwd', $json);
        $this->assertStringNotContainsString('secret@example.com', $json);
    }

    public function test_a_finding_with_no_valid_reference_at_all_exposes_none()
    {
        $project = $this->project();
        $finding = $this->finding($project, ['references' => ['javascript:alert(1)', 'ftp://example.com/x']]);

        $response = $this->actingAs(User::factory()->create())->get(route('findings.show', $finding))->assertOk();

        $this->assertSame([], $response->viewData('page')['props']['remediation']['references']);
    }

    public function test_reference_count_in_the_payload_stays_bounded()
    {
        $project = $this->project();
        $many = array_map(fn ($i) => "https://example.com/ref/{$i}", range(1, 40));
        $finding = $this->finding($project, ['references' => $many]);

        $response = $this->actingAs(User::factory()->create())->get(route('findings.show', $finding))->assertOk();

        $this->assertLessThanOrEqual(10, count($response->viewData('page')['props']['remediation']['references']));
    }

    // ---------------- (B) Owner identity never leaks through history ----------------

    public function test_admin_cannot_learn_the_owner_email_from_finding_history()
    {
        $owner = User::factory()->owner()->create(['email' => 'owner-secret@example.test', 'name' => 'The Real Owner']);
        $admin = User::factory()->admin()->create();
        $project = $this->project();
        $finding = $this->finding($project);
        $this->history($finding, ActorType::User, $owner->email);

        $response = $this->actingAs($admin)->get(route('findings.show', $finding))->assertOk();
        $entry = $response->viewData('page')['props']['status_history'][0];

        $this->assertNull($entry['actor_identifier']);
        $this->assertSame('Privileged user', $entry['actor_label']);
        $this->assertSame('user', $entry['actor_type']); // provenance kind is not hidden, only identity
        $json = json_encode($response->viewData('page')['props']);
        $this->assertStringNotContainsString($owner->email, $json);
        $this->assertStringNotContainsString('The Real Owner', $json);
    }

    public function test_a_plain_user_cannot_learn_the_owner_email_from_finding_history_either()
    {
        $owner = User::factory()->owner()->create(['email' => 'owner-secret-2@example.test']);
        $user = User::factory()->create();
        $project = $this->project();
        $finding = $this->finding($project);
        $this->history($finding, ActorType::User, $owner->email);

        $response = $this->actingAs($user)->get(route('findings.show', $finding))->assertOk();
        $entry = $response->viewData('page')['props']['status_history'][0];

        $this->assertNull($entry['actor_identifier']);
        $this->assertSame('Privileged user', $entry['actor_label']);
    }

    public function test_the_owner_sees_their_own_identity_in_their_own_history_entry()
    {
        $owner = User::factory()->owner()->create(['email' => 'owner-self@example.test']);
        $project = $this->project();
        $finding = $this->finding($project);
        $this->history($finding, ActorType::User, $owner->email);

        $response = $this->actingAs($owner)->get(route('findings.show', $finding))->assertOk();
        $entry = $response->viewData('page')['props']['status_history'][0];

        $this->assertSame($owner->email, $entry['actor_identifier']);
        $this->assertNull($entry['actor_label']);
    }

    public function test_an_ordinary_admin_actor_identity_remains_visible_to_any_viewer_unchanged(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'admin-visible@example.test']);
        $project = $this->project();
        $finding = $this->finding($project);
        $this->history($finding, ActorType::User, $admin->email);

        foreach ([User::factory()->create(), User::factory()->admin()->create(), User::factory()->owner()->create()] as $viewer) {
            $entry = $this->actingAs($viewer)->get(route('findings.show', $finding))->assertOk()
                ->viewData('page')['props']['status_history'][0];

            $this->assertSame($admin->email, $entry['actor_identifier']);
            $this->assertNull($entry['actor_label']);
        }
    }

    public function test_a_system_actor_entry_is_unaffected(): void
    {
        $project = $this->project();
        $finding = $this->finding($project);
        $this->history($finding, ActorType::System, null);

        $entry = $this->actingAs(User::factory()->create())->get(route('findings.show', $finding))
            ->assertOk()->viewData('page')['props']['status_history'][0];

        $this->assertSame('system', $entry['actor_type']);
        $this->assertNull($entry['actor_identifier']);
        $this->assertNull($entry['actor_label']);
    }

    public function test_an_actor_identifier_that_no_longer_matches_any_current_owner_email_is_shown(): void
    {
        // A former Owner's old email, now unrelated to the (new) current Owner
        // — the comparison is against the CURRENT Owner only, by design.
        User::factory()->owner()->create(['email' => 'current-owner@example.test']);
        $project = $this->project();
        $finding = $this->finding($project);
        $this->history($finding, ActorType::User, 'ex-owner@example.test');

        $entry = $this->actingAs(User::factory()->create())->get(route('findings.show', $finding))
            ->assertOk()->viewData('page')['props']['status_history'][0];

        $this->assertSame('ex-owner@example.test', $entry['actor_identifier']);
    }

    public function test_transitioning_through_the_real_lifecycle_service_as_owner_still_hides_the_email_from_others(): void
    {
        $owner = User::factory()->owner()->create(['email' => 'owner-lifecycle@example.test']);
        $project = $this->project();
        $finding = $this->finding($project);

        app(FindingLifecycleService::class)->transition($finding, FindingStatus::Confirmed, ActorType::User, actorIdentifier: $owner->email);

        $entry = $this->actingAs(User::factory()->create())->get(route('findings.show', $finding))
            ->assertOk()->viewData('page')['props']['status_history'][0];

        $this->assertNull($entry['actor_identifier']);
        $this->assertSame('Privileged user', $entry['actor_label']);

        // Internal persistence is untouched — only public presentation changed.
        $this->assertSame($owner->email, FindingStatusHistory::query()->where('finding_id', $finding->id)->firstOrFail()->actor_identifier);
    }

    public function test_inertia_assertable_shape_of_a_hidden_history_entry(): void
    {
        $owner = User::factory()->owner()->create();
        $project = $this->project();
        $finding = $this->finding($project);
        $this->history($finding, ActorType::User, $owner->email);

        $this->actingAs(User::factory()->create())->get(route('findings.show', $finding))->assertInertia(fn (Assert $page) => $page
            ->component('findings/show')
            ->where('status_history.0.actor_identifier', null)
            ->where('status_history.0.actor_label', 'Privileged user')
            ->missing('finding.references'));
    }

    // ---------------- MCP regression: identity stays hidden, unconditionally ----------------

    public function test_mcp_get_finding_and_get_finding_remediation_never_expose_owner_identity_either(): void
    {
        $owner = User::factory()->owner()->create(['email' => 'owner-mcp@example.test', 'name' => 'MCP Owner Name']);
        McpWorld::token(McpWorld::user(Role::User));
        $project = $this->project();
        $finding = $this->finding($project);
        $this->history($finding, ActorType::User, $owner->email);

        $viaFinding = json_encode(McpWorld::call(GetFinding::class, ['finding_id' => $finding->public_id]));
        $viaRemediation = json_encode(McpWorld::call(GetFindingRemediation::class, ['finding_id' => $finding->public_id]));

        foreach ([$viaFinding, $viaRemediation] as $json) {
            $this->assertStringNotContainsString($owner->email, (string) $json);
            $this->assertStringNotContainsString('MCP Owner Name', (string) $json);
            $this->assertStringNotContainsString('actor_identifier', (string) $json);
        }
    }
}
