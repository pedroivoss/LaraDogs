<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_first_administrator_non_interactively(): void
    {
        $this->artisan('laradogs:user:create-admin', [
            '--name' => 'Admin User',
            '--email' => 'admin@example.com',
        ])
            ->expectsQuestion('Administrator password', 'a-real-password')
            ->expectsQuestion('Confirm password', 'a-real-password')
            ->assertSuccessful();

        $user = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $this->assertTrue($user->is_admin);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('a-real-password', $user->password));
    }

    public function test_it_rejects_a_duplicate_admin_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->artisan('laradogs:user:create-admin', [
            '--name' => 'Second Admin',
            '--email' => 'taken@example.com',
        ])
            ->expectsQuestion('Administrator password', 'a-real-password')
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
