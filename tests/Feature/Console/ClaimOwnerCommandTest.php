<?php

namespace Tests\Feature\Console;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the "multiple pre-existing admins" upgrade scenario — see
 * `2026_09_11_000001_replace_is_admin_with_role_on_users_table`'s
 * docblock.
 */
class ClaimOwnerCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_promotes_an_existing_admin_to_owner(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'first-admin@example.com']);
        User::factory()->admin()->create(['email' => 'second-admin@example.com']);

        $this->artisan('laradogs:user:claim-owner', ['email' => 'first-admin@example.com'])
            ->assertSuccessful();

        $admin->refresh();
        $this->assertSame(Role::Owner, $admin->role);
        $this->assertSame(1, User::query()->where('role', Role::Owner)->count());
    }

    public function test_it_refuses_when_an_owner_already_exists(): void
    {
        User::factory()->owner()->create();
        $admin = User::factory()->admin()->create(['email' => 'admin@example.com']);

        $this->artisan('laradogs:user:claim-owner', ['email' => 'admin@example.com'])
            ->assertFailed();

        $admin->refresh();
        $this->assertSame(Role::Admin, $admin->role);
    }

    public function test_it_fails_for_an_unknown_email(): void
    {
        $this->artisan('laradogs:user:claim-owner', ['email' => 'nobody@example.com'])
            ->assertFailed();
    }

    public function test_it_can_promote_a_plain_user_too(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);

        $this->artisan('laradogs:user:claim-owner', ['email' => 'user@example.com'])
            ->assertSuccessful();

        $user->refresh();
        $this->assertSame(Role::Owner, $user->role);
    }
}
