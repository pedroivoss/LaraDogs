<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 7.1.2, PART F: public self-registration must be genuinely
 * unavailable, not merely hidden from the UI — see
 * config/fortify.php's `features` list, which no longer includes
 * Features::registration(). Both the view route and the submission
 * route must be absent (404), never merely redirect/reject with a
 * validation error (which would mean the endpoint still exists).
 */
class RegistrationDisabledTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_view_route_does_not_exist(): void
    {
        $response = $this->get('/register');

        $response->assertNotFound();
    }

    public function test_registration_cannot_be_submitted(): void
    {
        $response = $this->post('/register', [
            'name' => 'Attacker',
            'email' => 'attacker@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'attacker@example.com']);
        $this->assertGuest();
    }
}
