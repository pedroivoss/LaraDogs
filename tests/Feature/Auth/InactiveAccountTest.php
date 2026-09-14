<?php

namespace Tests\Feature\Auth;

use App\Audit\Findings\ActorType;
use App\Audit\Findings\FindingStatus;
use App\Audit\Findings\Lifecycle\FindingLifecycleService;
use App\Audit\Projects\RegisterProject;
use App\Models\Audit\Finding;
use App\Models\Audit\FindingStatusHistory;
use App\Models\Audit\Scan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 7.1.3: `is_active` enforcement. Two distinct mechanisms, tested
 * separately — see App\Providers\FortifyServiceProvider::configureActions()
 * (login-time) and App\Http\Middleware\EnsureUserIsActive (every
 * subsequent request, including an already-authenticated session).
 */
class InactiveAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_user_cannot_authenticate(): void
    {
        $user = User::factory()->inactive()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
    }

    public function test_inactive_admin_cannot_authenticate(): void
    {
        $admin = User::factory()->admin()->inactive()->create();

        $this->post('/login', [
            'email' => $admin->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
    }

    public function test_inactive_account_login_failure_uses_the_same_generic_message_as_a_wrong_password(): void
    {
        $active = User::factory()->create();
        $inactive = User::factory()->inactive()->create();

        $wrongPasswordResponse = $this->post('/login', [
            'email' => $active->email,
            'password' => 'not-the-right-password',
        ]);

        $inactiveResponse = $this->post('/login', [
            'email' => $inactive->email,
            'password' => 'password',
        ]);

        // Both fail with the exact same generic message Fortify's own
        // `AttemptToAuthenticate::throwFailedAuthenticationException()`
        // uses for every failed login (`trans('auth.failed')`) —
        // genuinely indistinguishable to an unauthenticated caller (see
        // App\Providers\FortifyServiceProvider::configureActions()).
        $wrongPasswordResponse->assertSessionHasErrors(['email' => trans('auth.failed')]);
        $inactiveResponse->assertSessionHasErrors(['email' => trans('auth.failed')]);
        $this->assertSame($wrongPasswordResponse->getStatusCode(), $inactiveResponse->getStatusCode());
    }

    public function test_an_already_authenticated_session_loses_access_immediately_after_deactivation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertOk();

        // Direct property assignment, not update([...]) — is_active is
        // deliberately not mass-assignable (see User's #[Fillable] list).
        $user->is_active = false;
        $user->save();

        $this->actingAs($user)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_historical_finding_actor_references_survive_deactivation(): void
    {
        // actor_identifier on finding_status_histories is a plain string
        // snapshot (the actor's email at transition time), never a
        // foreign key to users — see App\Audit\Findings\ActorType's own
        // docblock. Build a real transition, deactivate the acting user
        // afterward, and confirm the history row is completely
        // unaffected (not orphaned, not cascade-deleted, not nulled).
        $user = User::factory()->create(['email' => 'reviewer@example.com']);

        $path = dirname(__DIR__, 2).'/Fixtures/discovery/laravel-blade';
        $project = (new RegisterProject)->register($path)->project;
        $scan = Scan::query()->create([
            'project_id' => $project->id,
            'status' => 'completed',
            'started_at' => now(),
            'finished_at' => now(),
            'project_profile' => ['project' => ['type' => 'laravel']],
        ]);
        $finding = Finding::query()->create([
            'project_id' => $project->id,
            'fingerprint' => hash('sha256', uniqid()),
            'fingerprint_version' => 'v1',
            'rule_id' => 'LARA-SEC-001',
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
        ]);

        (new FindingLifecycleService)->transition(
            $finding,
            FindingStatus::Confirmed,
            ActorType::User,
            actorIdentifier: $user->email,
            scan: $scan,
        );

        $historyId = FindingStatusHistory::query()
            ->where('finding_id', $finding->id)
            ->where('actor_identifier', 'reviewer@example.com')
            ->firstOrFail()
            ->id;

        $user->is_active = false;
        $user->save();

        $history = FindingStatusHistory::query()->findOrFail($historyId);
        $this->assertSame('reviewer@example.com', $history->actor_identifier);
        $this->assertSame(FindingStatus::Confirmed, $finding->fresh()->status);
    }
}
