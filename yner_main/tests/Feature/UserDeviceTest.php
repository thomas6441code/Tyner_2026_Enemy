<?php

namespace Tests\Feature;

use App\Enums\DeviceStatus;
use App\Enums\RoleName;
use App\Models\Employee;
use App\Models\User;
use App\Models\UserDevice;
use App\Notifications\SystemNotification;
use App\Services\WebAuthn\CredentialSourceRepository;
use App\Services\WebAuthnService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * WebAuthn device registration and revocation.
 *
 * Real ceremony cryptography cannot be produced inside PHPUnit — a genuine attestation needs
 * a hardware or platform authenticator holding a private key. So these tests pin everything
 * around the crypto: challenge issuance and single-use, replay refusal, ownership scoping,
 * revocation semantics, and the RP-ID / revoked filtering in the credential repository.
 * The signature verification itself is the library's responsibility and is exercised by its
 * own suite.
 */
class UserDeviceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        config()->set('webauthn.rp_id', 'eapms.test');
    }

    private function employeeUser(string $role = RoleName::Employee->value, string $status = 'active'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        Employee::create([
            'user_id' => $user->id,
            'employee_code' => 'E'.fake()->unique()->numberBetween(1, 100_000),
            'first_name' => 'Amina',
            'last_name' => 'Juma',
            'status' => $status,
        ]);

        return $user->fresh();
    }

    private function device(User $user, array $overrides = []): UserDevice
    {
        return UserDevice::create($overrides + [
            'user_id' => $user->id,
            'credential_id' => 'cred-'.fake()->unique()->numberBetween(1, 100_000),
            'public_key' => '{}',
            'sign_count' => 0,
            'rp_id' => 'eapms.test',
            'device_name' => 'Test Phone',
            'status' => DeviceStatus::Active,
        ]);
    }

    public function test_the_devices_page_loads_for_an_authenticated_user(): void
    {
        $this->actingAs($this->employeeUser())->get('/devices')->assertOk();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/devices')->assertRedirect('/login');
    }

    public function test_an_employee_sees_only_their_own_devices(): void
    {
        $user = $this->employeeUser();
        $other = $this->employeeUser();
        $this->device($user, ['device_name' => 'My Phone']);
        $this->device($other, ['device_name' => 'Their Phone']);

        $this->actingAs($user)->get('/devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('devices.data', 1)
                ->where('devices.data.0.device_name', 'My Phone'));
    }

    public function test_an_admin_sees_every_device(): void
    {
        $admin = $this->employeeUser(RoleName::Admin->value);
        $other = $this->employeeUser();
        $this->device($admin);
        $this->device($other);

        $this->actingAs($admin)->get('/devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('devices.data', 2));
    }

    /*
    |--------------------------------------------------------------------------
    | Registration ceremony
    |--------------------------------------------------------------------------
    */

    public function test_the_options_endpoint_returns_options_and_stores_the_challenge(): void
    {
        $user = $this->employeeUser();

        $response = $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/devices/register/options');

        $response->assertOk()
            ->assertJsonPath('rp.id', 'eapms.test')
            ->assertJsonStructure(['challenge', 'rp' => ['id'], 'user' => ['id'], 'pubKeyCredParams']);

        // The challenge lives server-side. If it were only in the response body, the client
        // could choose it, and a chosen challenge defeats the whole replay defence.
        $this->assertNotNull(session(config('webauthn.session.registration')));
    }

    public function test_registration_requires_a_confirmed_password(): void
    {
        // Registering an authenticator on a hijacked session is a durable privilege
        // escalation, so it sits behind a password re-prompt.
        $this->actingAs($this->employeeUser())
            ->post('/devices/register/options')
            ->assertRedirect(route('password.confirm'));
    }

    public function test_a_user_without_an_active_employee_record_cannot_register(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RoleName::Employee->value);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/devices/register/options')
            ->assertForbidden();
    }

    public function test_an_inactive_employee_cannot_register_a_device(): void
    {
        $user = $this->employeeUser(status: 'inactive');

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/devices/register/options')
            ->assertForbidden();
    }

    public function test_verify_is_rejected_when_no_challenge_was_issued(): void
    {
        // The replay guard: a captured credential payload replayed without a live server-side
        // challenge must fail, not be accepted on its own merits.
        $this->actingAs($this->employeeUser())
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/devices/register/verify', [
                'device_name' => 'Stolen Phone',
                'credential' => ['id' => 'x', 'rawId' => 'x', 'type' => 'public-key', 'response' => []],
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('user_devices', 0);
    }

    public function test_verify_rejects_a_malformed_credential_payload(): void
    {
        $user = $this->employeeUser();

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/devices/register/options');

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/devices/register/verify', [
                'device_name' => 'Phone',
                'credential' => ['nonsense' => true],
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('user_devices', 0);
    }

    public function test_the_ceremony_endpoints_are_throttled(): void
    {
        $user = $this->employeeUser();

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
                ->postJson('/devices/register/options')->assertOk();
        }

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/devices/register/options')
            ->assertStatus(429);
    }

    public function test_a_challenge_is_single_use(): void
    {
        $user = $this->employeeUser();
        $service = app(WebAuthnService::class);

        $service->registrationOptions($user);
        $this->assertNotNull(session(config('webauthn.session.registration')));

        // A failed verify must still consume the challenge, otherwise an attacker gets
        // unlimited attempts against one live challenge.
        try {
            $service->verifyRegistration($user, ['id' => 'x'], 'Phone');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertNull(session(config('webauthn.session.registration')));
    }

    /*
    |--------------------------------------------------------------------------
    | Revocation
    |--------------------------------------------------------------------------
    */

    public function test_an_owner_cannot_revoke_their_own_device(): void
    {
        $user = $this->employeeUser();
        $device = $this->device($user);

        // Self-revocation is the hinge of the whole binding. If an employee could unlink and
        // immediately re-link, "one account, one device" would collapse into a two-click
        // formality — hand the phone over, unlink, re-link. They file a reset request instead.
        $this->actingAs($user)->delete("/devices/{$device->id}")->assertForbidden();

        $this->assertSame(DeviceStatus::Active, $device->fresh()->status);
    }

    public function test_a_user_cannot_revoke_someone_elses_device(): void
    {
        $user = $this->employeeUser();
        $victim = $this->employeeUser();
        $device = $this->device($victim);

        $this->actingAs($user)->delete("/devices/{$device->id}")->assertForbidden();

        $this->assertSame(DeviceStatus::Active, $device->fresh()->status);
    }

    public function test_an_admin_can_revoke_anyones_device(): void
    {
        Notification::fake();
        $admin = $this->employeeUser(RoleName::Admin->value);
        $victim = $this->employeeUser();
        $device = $this->device($victim);

        $this->actingAs($admin)->delete("/devices/{$device->id}")->assertRedirect('/devices');

        $this->assertSame(DeviceStatus::Revoked, $device->fresh()->status);
    }

    public function test_revoking_notifies_the_owner(): void
    {
        Notification::fake();
        $admin = $this->employeeUser(RoleName::Admin->value);
        $victim = $this->employeeUser();
        $device = $this->device($victim);

        $this->actingAs($admin)->delete("/devices/{$device->id}");

        Notification::assertSentTo($victim, SystemNotification::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Credential repository filtering
    |--------------------------------------------------------------------------
    */

    public function test_revoked_devices_are_not_findable(): void
    {
        $user = $this->employeeUser();
        $device = $this->device($user);
        $device->revoke('test');

        $repository = app(CredentialSourceRepository::class);

        $this->assertNull($repository->findDeviceForUser($user, $device->credential_id));
        $this->assertCount(0, $repository->usableDevicesFor($user));
    }

    public function test_a_credential_bound_to_another_rp_id_is_not_findable(): void
    {
        // Credentials are cryptographically bound to the RP ID they were created under. One
        // from an old domain can never verify here, so it is excluded up front rather than
        // left to fail with an opaque signature error.
        $user = $this->employeeUser();
        $device = $this->device($user, ['rp_id' => 'old-domain.test']);

        $repository = app(CredentialSourceRepository::class);

        $this->assertNull($repository->findDeviceForUser($user, $device->credential_id));
    }

    public function test_a_credential_belonging_to_another_user_is_not_findable_for_this_one(): void
    {
        $user = $this->employeeUser();
        $other = $this->employeeUser();
        $device = $this->device($other);

        $repository = app(CredentialSourceRepository::class);

        $this->assertNull($repository->findDeviceForUser($user, $device->credential_id));
        $this->assertNotNull($repository->findDeviceForUser($other, $device->credential_id));
    }

    public function test_the_rp_id_falls_back_to_the_app_url_host(): void
    {
        config()->set('webauthn.rp_id', null);
        config()->set('app.url', 'https://eapms.example.ac.tz');

        $this->assertSame('eapms.example.ac.tz', app(WebAuthnService::class)->rpId());
    }
}
