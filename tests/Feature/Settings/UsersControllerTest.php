<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UsersControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_reach_user_management(): void
    {
        $this->get('/settings/users')->assertRedirect('/login');
    }

    public function test_regular_user_is_denied_user_management(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/settings/users')->assertNotFound();
        $this->actingAs($user)->get('/settings/users/create')->assertNotFound();
        $this->actingAs($user)->post('/settings/users', [
            'name' => 'New',
            'email' => 'new@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();
    }

    public function test_admin_can_list_users(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['name' => 'Someone Else']);

        $this->actingAs($admin)
            ->get('/settings/users')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('users', 2));
    }

    public function test_admin_can_create_a_user(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/settings/users', [
            'name' => 'Brand New',
            'email' => 'brand-new@example.com',
            'password' => 'a-real-password',
            'password_confirmation' => 'a-real-password',
        ]);

        $response->assertRedirect('/settings/users');

        $created = User::query()->where('email', 'brand-new@example.com')->firstOrFail();
        $this->assertFalse($created->is_admin);
        $this->assertTrue(Hash::check('a-real-password', $created->password));
    }

    public function test_admin_can_edit_a_users_name_and_email(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create(['name' => 'Old Name', 'email' => 'old@example.com']);

        $response = $this->actingAs($admin)->patch("/settings/users/{$target->id}", [
            'name' => 'New Name',
            'email' => 'new@example.com',
        ]);

        $response->assertRedirect("/settings/users/{$target->id}/edit");

        $target->refresh();
        $this->assertSame('New Name', $target->name);
        $this->assertSame('new@example.com', $target->email);
    }

    public function test_admin_can_set_another_users_password_without_knowing_the_current_one(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();

        $response = $this->actingAs($admin)->put("/settings/users/{$target->id}/password", [
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ]);

        $response->assertRedirect("/settings/users/{$target->id}/edit");

        $target->refresh();
        $this->assertTrue(Hash::check('a-new-password', $target->password));
    }

    public function test_duplicate_email_is_rejected_when_creating_a_user(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->actingAs($admin)->post('/settings/users', [
            'name' => 'Someone',
            'email' => 'taken@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertInvalid(['email']);
    }
}
