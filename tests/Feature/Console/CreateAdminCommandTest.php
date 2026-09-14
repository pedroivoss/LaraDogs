<?php

namespace Tests\Feature\Console;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase 7.1.3 semantics: `laradogs:user:create-admin` now requires an
 * Instance Owner to already exist — see that command's own docblock for
 * why this changed from Phase 7.1.2 (where it created the first
 * privileged account at all).
 */
class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_to_run_when_no_owner_exists_yet(): void
    {
        $this->artisan('laradogs:user:create-admin', [
            '--name' => 'Admin User',
            '--email' => 'admin@example.com',
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);
    }

    public function test_it_creates_an_admin_when_an_owner_already_exists(): void
    {
        User::factory()->owner()->create();

        $this->artisan('laradogs:user:create-admin', [
            '--name' => 'Admin User',
            '--email' => 'admin@example.com',
        ])
            ->expectsQuestion('Password', 'a-real-password')
            ->expectsQuestion('Confirm password', 'a-real-password')
            ->assertSuccessful();

        $user = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $this->assertSame(Role::Admin, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('a-real-password', $user->password));
    }

    public function test_it_rejects_a_duplicate_admin_email(): void
    {
        User::factory()->owner()->create();
        User::factory()->create(['email' => 'taken@example.com']);

        $this->artisan('laradogs:user:create-admin', [
            '--name' => 'Second Admin',
            '--email' => 'taken@example.com',
        ])
            ->expectsQuestion('Password', 'a-real-password')
            ->expectsQuestion('Confirm password', 'a-real-password')
            ->assertFailed();

        $this->assertSame(1, User::query()->where('email', 'taken@example.com')->count());
    }

    public function test_no_default_admin_credential_is_ever_created_automatically(): void
    {
        // Nothing runs the command in a fresh install — assert LaraDogs
        // never ships/auto-provisions a known account on its own.
        $this->assertDatabaseMissing('users', ['email' => 'admin@laradogs.test']);
        $this->assertDatabaseCount('users', 0);
    }
}
