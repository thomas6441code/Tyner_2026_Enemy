<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\AccountInvitation;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    /**
     * @return array{0: AccountInvitation, 1: string, 2: Employee}
     */
    private function issueInvitation(array $overrides = []): array
    {
        $employee = Employee::create([
            'employee_code' => 'EMP-0100',
            'first_name' => 'Thomas',
            'last_name' => 'Bonaventure',
            'status' => 'inactive',
        ]);

        $result = AccountInvitation::issue($employee, 'thomas@example.com');
        $invitation = $result['invitation'];

        if ($overrides !== []) {
            $invitation->forceFill($overrides)->save();
        }

        return [$invitation, $result['plainToken'], $employee];
    }

    private function activationUrl(AccountInvitation $invitation, string $token): string
    {
        return route('account.activate', ['uuid' => $invitation->uuid, 'token' => $token]);
    }

    public function test_a_valid_invitation_renders_the_activation_page(): void
    {
        [$invitation, $token] = $this->issueInvitation();

        $this->get($this->activationUrl($invitation, $token))->assertOk();
    }

    public function test_activation_creates_the_account_and_links_the_employee(): void
    {
        [$invitation, $token, $employee] = $this->issueInvitation();

        $response = $this->post($this->activationUrl($invitation, $token), [
            'password' => 'Str0ng-Passw0rd!',
            'password_confirmation' => 'Str0ng-Passw0rd!',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        $user = User::sole();
        $this->assertSame('thomas@example.com', $user->email);
        $this->assertTrue($user->hasRole(RoleName::Employee->value));
        $this->assertNotNull($user->email_verified_at);

        $employee->refresh();
        $this->assertSame($user->id, $employee->user_id);
        $this->assertSame('active', $employee->status);

        $this->assertNotNull($invitation->refresh()->used_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.activated']);
    }

    public function test_an_invitation_cannot_be_redeemed_twice(): void
    {
        [$invitation, $token] = $this->issueInvitation();

        $payload = ['password' => 'Str0ng-Passw0rd!', 'password_confirmation' => 'Str0ng-Passw0rd!'];
        $this->post($this->activationUrl($invitation, $token), $payload);

        // Simulate the link being opened again from a different browser: activation routes
        // are guest-only, so an authenticated session would be redirected before reaching
        // the controller and would not exercise the reuse path at all.
        Auth::logout();
        $this->flushSession();

        $this->get($this->activationUrl($invitation, $token))
            ->assertInertia(fn ($page) => $page->where('reason', 'used'));
        $this->post($this->activationUrl($invitation, $token), $payload);

        // The single-use guarantee: exactly one account, ever.
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'invitation.reuse_attempt']);
    }

    public function test_a_used_invitation_shows_the_invalid_page(): void
    {
        [$invitation, $token] = $this->issueInvitation(['used_at' => now()]);

        $this->get($this->activationUrl($invitation, $token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('auth/activation-invalid')
                ->where('reason', 'used'));
    }

    public function test_an_expired_invitation_is_refused(): void
    {
        [$invitation, $token] = $this->issueInvitation(['expires_at' => now()->subDay()]);

        $this->get($this->activationUrl($invitation, $token))
            ->assertInertia(fn ($page) => $page
                ->component('auth/activation-invalid')
                ->where('reason', 'expired'));

        $this->post($this->activationUrl($invitation, $token), [
            'password' => 'Str0ng-Passw0rd!',
            'password_confirmation' => 'Str0ng-Passw0rd!',
        ]);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_revoked_invitation_is_refused(): void
    {
        [$invitation, $token] = $this->issueInvitation(['revoked_at' => now()]);

        $this->get($this->activationUrl($invitation, $token))
            ->assertInertia(fn ($page) => $page->where('reason', 'revoked'));

        $this->post($this->activationUrl($invitation, $token), [
            'password' => 'Str0ng-Passw0rd!',
            'password_confirmation' => 'Str0ng-Passw0rd!',
        ]);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_wrong_token_is_indistinguishable_from_an_unknown_uuid(): void
    {
        [$invitation] = $this->issueInvitation();

        $wrongToken = $this->get($this->activationUrl($invitation, str_repeat('a', 64)));
        $unknownUuid = $this->get(route('account.activate', [
            'uuid' => (string) Str::uuid(),
            'token' => str_repeat('a', 64),
        ]));

        foreach ([$wrongToken, $unknownUuid] as $response) {
            $response->assertOk()->assertInertia(fn ($page) => $page
                ->component('auth/activation-invalid')
                ->where('reason', 'invalid'));
        }
    }

    public function test_activation_enforces_password_rules(): void
    {
        [$invitation, $token] = $this->issueInvitation();

        $this->post($this->activationUrl($invitation, $token), [
            'password' => 'short',
            'password_confirmation' => 'mismatch',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseCount('users', 0);
        $this->assertNull($invitation->refresh()->used_at);
    }
}
