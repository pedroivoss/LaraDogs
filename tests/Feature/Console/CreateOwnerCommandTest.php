<?php

namespace Tests\Feature\Console;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateOwnerCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_instance_owner_non_interactively(): void
    {
        $this->artisan('laradogs:user:create-owner', [
            '--name' => 'Owner User',
            '--email' => 'owner@example.com',
        ])
            ->expectsQuestion('Password', 'a-real-password')
            ->expectsQuestion('Confirm password', 'a-real-password')
            ->assertSuccessful();

        $user = User::query()->where('email', 'owner@example.com')->firstOrFail();

        $this->assertSame(Role::Owner, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('a-real-password', $user->password));
    }

    public function test_password_is_hashed_never_stored_in_plaintext(): void
    {
        $this->artisan('laradogs:user:create-owner', [
            '--name' => 'Owner User',
            '--email' => 'owner@example.com',
        ])
            ->expectsQuestion('Password', 'a-real-password')
            ->expectsQuestion('Confirm password', 'a-real-password')
            ->assertSuccessful();

        $user = User::query()->where('email', 'owner@example.com')->firstOrFail();

        $this->assertNotSame('a-real-password', $user->password);
        $this->assertStringStartsWith('$2y$', $user->password);
    }

    public function test_it_refuses_to_create_a_second_owner(): void
    {
        User::factory()->owner()->create();

        $this->artisan('laradogs:user:create-owner', [
            '--name' => 'Second Owner',
            '--email' => 'second-owner@example.com',
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'second-owner@example.com']);
        $this->assertSame(1, User::query()->where('role', Role::Owner)->count());
    }

    public function test_the_created_owner_can_authenticate(): void
    {
        $this->artisan('laradogs:user:create-owner', [
            '--name' => 'Owner User',
            '--email' => 'owner@example.com',
        ])
            ->expectsQuestion('Password', 'a-real-password')
            ->expectsQuestion('Confirm password', 'a-real-password')
            ->assertSuccessful();

        $response = $this->post('/login', [
            'email' => 'owner@example.com',
            'password' => 'a-real-password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/dashboard');
    }
}
