<?php

namespace Tests\Feature\Auth;

use App\Enums\RegistrationStatus;
use App\Models\RegistrationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `/register` used to create an authenticated user with no role and no Employee link.
 *
 * These tests pin the replacement behaviour: the endpoint files a request for review and
 * creates nothing. test_registering_never_creates_an_account is the regression test that
 * keeps the open-signup hole closed.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_registering_never_creates_an_account(): void
    {
        $response = $this->post('/register', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
        ]);

        $response->assertRedirect(route('registration-request.submitted'));
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_registering_stores_a_pending_request(): void
    {
        $this->post('/register', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'phone' => '0700000000',
        ]);

        $registrationRequest = RegistrationRequest::sole();

        $this->assertSame('test@example.com', $registrationRequest->email);
        $this->assertSame(RegistrationStatus::Pending, $registrationRequest->status);
        $this->assertNull($registrationRequest->employee_id);
    }

    public function test_registration_requires_identity_fields(): void
    {
        $response = $this->post('/register', [
            'first_name' => '',
            'last_name' => '',
            'email' => 'not-an-email',
        ]);

        $response->assertSessionHasErrors(['first_name', 'last_name', 'email']);
        $this->assertDatabaseCount('registration_requests', 0);
    }
}
