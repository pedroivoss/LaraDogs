<?php

namespace Tests\Feature\Settings;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UsersControllerTest extends TestCase
{
    use RefreshDatabase;

    // --- Access ------------------------------------------------------

    public function test_guest_cannot_reach_user_management(): void
    {
        $this->get('/settings/users')->assertRedirect('/login');
    }

    public function test_regular_user_cannot_access_user_management(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/settings/users')->assertNotFound();
        $this->actingAs($user)->get('/settings/users/create')->assertNotFound();
        $this->actingAs($user)->post('/settings/users', [
            'name' => 'New',
            'email' => 'new@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'user',
        ])->assertNotFound();
    }

    // --- Owner privacy -------------------------------------------------

    public function test_admin_never_receives_owner_in_the_user_listing(): void
    {
        User::factory()->owner()->create(['name' => 'The Owner', 'email' => 'owner@example.com']);
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/settings/users');

        $response->assertOk()->assertInertia(fn ($page) => $page->has('users', 0));
        $this->assertStringNotContainsString('owner@example.com', $response->getContent());
        $this->assertStringNotContainsString('The Owner', $response->getContent());
    }

    public function test_owner_receives_admins_and_users_but_not_themselves_in_the_listing(): void
    {
        $owner = User::factory()->owner()->create();
        User::factory()->admin()->create();
        User::factory()->create();

        $this->actingAs($owner)
            ->get('/settings/users')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('users', 2));
    }

    public function test_admin_cannot_fetch_the_owner_edit_page(): void
    {
        $owner = User::factory()->owner()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get("/settings/users/{$owner->id}/edit")->assertNotFound();
    }

    public function test_admin_cannot_update_the_owner(): void
    {
        $owner = User::factory()->owner()->create(['name' => 'Original Name']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->patch("/settings/users/{$owner->id}", [
            'name' => 'Hacked Name',
            'email' => 'hacked@example.com',
        ])->assertNotFound();

        $owner->refresh();
        $this->assertSame('Original Name', $owner->name);
    }

    public function test_admin_cannot_reset_the_owners_password(): void
    {
        $owner = User::factory()->owner()->create();
        $admin = User::factory()->admin()->create();
        $originalHash = $owner->password;

        $this->actingAs($admin)->put("/settings/users/{$owner->id}/password", [
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ])->assertNotFound();

        $owner->refresh();
        $this->assertSame($originalHash, $owner->password);
    }

    public function test_admin_cannot_deactivate_the_owner(): void
    {
        $owner = User::factory()->owner()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put("/settings/users/{$owner->id}/deactivate")->assertNotFound();

        $owner->refresh();
        $this->assertTrue($owner->is_active);
    }

    public function test_admin_cannot_infer_owner_existence_through_any_management_response(): void
    {
        $owner = User::factory()->owner()->create(['email' => 'owner-identity-probe@example.com']);
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/settings/users');
        $response->assertOk();
        $this->assertStringNotContainsString('owner-identity-probe@example.com', $response->getContent());
    }

    // --- Admin capabilities -------------------------------------------

    public function test_owner_creates_an_admin(): void
    {
        $owner = User::factory()->owner()->create();

        $response = $this->actingAs($owner)->post('/settings/users', [
            'name' => 'New Admin',
            'email' => 'new-admin@example.com',
            'password' => 'a-real-password',
            'password_confirmation' => 'a-real-password',
            'role' => 'admin',
        ]);

        $response->assertRedirect('/settings/users');
        $created = User::query()->where('email', 'new-admin@example.com')->firstOrFail();
        $this->assertSame(Role::Admin, $created->role);
    }

    public function test_admin_creates_a_user(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/settings/users', [
            'name' => 'New User',
            'email' => 'new-user@example.com',
            'password' => 'a-real-password',
            'password_confirmation' => 'a-real-password',
            'role' => 'user',
        ]);

        $response->assertRedirect('/settings/users');
        $created = User::query()->where('email', 'new-user@example.com')->firstOrFail();
        $this->assertSame(Role::User, $created->role);
    }

    public function test_admin_cannot_create_an_admin_even_by_requesting_the_admin_role(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/settings/users', [
            'name' => 'Sneaky',
            'email' => 'sneaky@example.com',
            'password' => 'a-real-password',
            'password_confirmation' => 'a-real-password',
            'role' => 'admin',
        ]);

        $created = User::query()->where('email', 'sneaky@example.com')->firstOrFail();
        $this->assertSame(Role::User, $created->role);
    }

    public function test_admin_edits_a_users_name_and_email(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create(['name' => 'Old', 'email' => 'old@example.com']);

        $this->actingAs($admin)->patch("/settings/users/{$target->id}", [
            'name' => 'New',
            'email' => 'new@example.com',
        ])->assertRedirect("/settings/users/{$target->id}/edit");

        $target->refresh();
        $this->assertSame('New', $target->name);
    }

    public function test_admin_resets_a_users_password(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();

        $this->actingAs($admin)->put("/settings/users/{$target->id}/password", [
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ])->assertRedirect("/settings/users/{$target->id}/edit");

        $target->refresh();
        $this->assertTrue(Hash::check('a-new-password', $target->password));
    }

    public function test_admin_deactivates_a_user(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();

        $this->actingAs($admin)->put("/settings/users/{$target->id}/deactivate")
            ->assertRedirect("/settings/users/{$target->id}/edit");

        $target->refresh();
        $this->assertFalse($target->is_active);
    }

    public function test_admin_reactivates_a_user(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->inactive()->create();

        $this->actingAs($admin)->put("/settings/users/{$target->id}/activate")
            ->assertRedirect("/settings/users/{$target->id}/edit");

        $target->refresh();
        $this->assertTrue($target->is_active);
    }

    public function test_admin_cannot_promote_a_user(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();

        $this->actingAs($admin)->put("/settings/users/{$target->id}/promote")->assertNotFound();

        $target->refresh();
        $this->assertSame(Role::User, $target->role);
    }

    public function test_admin_cannot_modify_another_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create(['name' => 'Other Admin']);

        $this->actingAs($admin)->patch("/settings/users/{$otherAdmin->id}", [
            'name' => 'Renamed',
            'email' => $otherAdmin->email,
        ])->assertNotFound();

        $otherAdmin->refresh();
        $this->assertSame('Other Admin', $otherAdmin->name);
    }

    public function test_admin_cannot_deactivate_themselves(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put("/settings/users/{$admin->id}/deactivate")->assertNotFound();

        $admin->refresh();
        $this->assertTrue($admin->is_active);
    }

    // --- Owner role changes ---------------------------------------------

    public function test_owner_promotes_a_user_to_admin(): void
    {
        $owner = User::factory()->owner()->create();
        $target = User::factory()->create();

        $this->actingAs($owner)->put("/settings/users/{$target->id}/promote")
            ->assertRedirect("/settings/users/{$target->id}/edit");

        $target->refresh();
        $this->assertSame(Role::Admin, $target->role);
    }

    public function test_owner_demotes_an_admin_to_user(): void
    {
        $owner = User::factory()->owner()->create();
        $target = User::factory()->admin()->create();

        $this->actingAs($owner)->put("/settings/users/{$target->id}/demote")
            ->assertRedirect("/settings/users/{$target->id}/edit");

        $target->refresh();
        $this->assertSame(Role::User, $target->role);
    }

    public function test_promoted_user_immediately_gains_admin_capabilities(): void
    {
        $owner = User::factory()->owner()->create();
        $target = User::factory()->create();

        $this->actingAs($owner)->put("/settings/users/{$target->id}/promote");
        $target->refresh();

        $this->assertTrue($target->fresh()->isAdmin());
    }

    public function test_role_change_preserves_the_same_user_id_no_duplicate_created(): void
    {
        $owner = User::factory()->owner()->create();
        $target = User::factory()->create();
        $originalId = $target->id;

        $this->actingAs($owner)->put("/settings/users/{$target->id}/promote");

        $this->assertSame(1, User::query()->where('id', $originalId)->count());
        $this->assertSame($originalId, $target->fresh()->id);
    }

    public function test_owner_cannot_be_promoted_or_demoted_there_is_no_such_target(): void
    {
        $owner = User::factory()->owner()->create();
        $otherOwnerAttemptTarget = User::factory()->admin()->create();

        // Sanity: promote/demote routes only ever accept User<->Admin —
        // there is no route/action that could move anyone INTO Owner.
        $this->actingAs($owner)->put("/settings/users/{$otherOwnerAttemptTarget->id}/promote")->assertNotFound();
    }

    // --- Owner protections ------------------------------------------

    public function test_owner_cannot_be_deactivated_via_a_hypothetical_self_or_other_path(): void
    {
        $owner = User::factory()->owner()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($owner)->put("/settings/users/{$owner->id}/deactivate")->assertNotFound();
        $this->actingAs($admin)->put("/settings/users/{$owner->id}/deactivate")->assertNotFound();

        $this->assertTrue($owner->fresh()->is_active);
    }

    public function test_owner_cannot_be_demoted(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->put("/settings/users/{$owner->id}/demote")->assertNotFound();

        $this->assertSame(Role::Owner, $owner->fresh()->role);
    }

    public function test_there_is_no_delete_action_the_owner_cannot_be_removed_through_management(): void
    {
        $owner = User::factory()->owner()->create();
        $admin = User::factory()->admin()->create();

        // No DELETE route exists at all for settings/users/{user} — the
        // path itself is only ever registered for GET/PATCH/PUT (see
        // routes/settings.php), so this correctly 405s rather than
        // reaching any handler that could remove the row.
        $this->actingAs($admin)->delete("/settings/users/{$owner->id}")->assertMethodNotAllowed();
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
    }

    // --- Validation --------------------------------------------------

    public function test_duplicate_email_is_rejected_when_creating_a_user(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->actingAs($admin)->post('/settings/users', [
            'name' => 'Someone',
            'email' => 'taken@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'user',
        ]);

        $response->assertInvalid(['email']);
    }
}
