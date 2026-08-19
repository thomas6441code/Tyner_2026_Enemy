<?php

namespace Tests\Feature;

use App\Enums\DeviceResetStatus;
use App\Enums\DeviceStatus;
use App\Enums\RoleName;
use App\Models\DeviceResetRequest;
use App\Models\Employee;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\DeviceTokenService;
use App\Services\WebAuthn\CredentialSourceRepository;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Symfony\Component\Uid\Uuid;
use Tests\TestCase;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * "One account, one device" — the three enforcement layers, tested separately.
 *
 * The rule is enforced in three places on purpose, because each layer has a gap the others
 * cover: the authenticator's excludeCredentials list can be dropped by a dishonest client, the
 * binding token can be cleared by the user, and a unique index cannot tell two handsets apart.
 * A suite that only proved one of them would not have proved the rule.
 *
 * The check-in half of the enforcement lives in MobileCheckInTest, next to the rest of the gate
 * chain it belongs to.
 */
class DeviceBindingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        config()->set('webauthn.rp_id', 'eapms.test');
    }

    private function employeeUser(string $status = 'active'): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleName::Employee->value);

        Employee::create([
            'user_id' => $user->id,
            'employee_code' => 'E'.fake()->unique()->numberBetween(1, 100_000),
            'first_name' => 'Amina',
            'last_name' => 'Juma',
            'status' => $status,
        ]);

        return $user->fresh();
    }

    /**
     * A device whose `public_key` is a genuinely deserializable CredentialRecord.
     *
     * Most tests can get away with '{}' there, but anything that builds descriptors round-trips
     * the record through the library's serializer, so those need the real shape.
     */
    private function device(User $user, array $overrides = []): UserDevice
    {
        $rawId = random_bytes(32);

        $record = CredentialRecord::create(
            publicKeyCredentialId: $rawId,
            type: 'public-key',
            transports: ['internal'],
            attestationType: 'none',
            trustPath: EmptyTrustPath::create(),
            aaguid: Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            credentialPublicKey: random_bytes(64),
            userHandle: (string) $user->id,
            counter: 0,
        );

        $serializer = (new WebauthnSerializerFactory(
            new AttestationStatementSupportManager([new NoneAttestationStatementSupport]),
        ))->create();

        return UserDevice::create($overrides + [
            'user_id' => $user->id,
            'credential_id' => rtrim(strtr(base64_encode($rawId), '+/', '-_'), '='),
            'public_key' => $serializer->serialize($record, 'json'),
            'rp_id' => 'eapms.test',
            'device_name' => 'Test Phone',
        ]);
    }

    private function approvedReset(User $user, ?Carbon $until = null): DeviceResetRequest
    {
        $reset = new DeviceResetRequest(['reason' => 'My phone was stolen last night.']);
        $reset->user_id = $user->id;
        $reset->status = DeviceResetStatus::Approved;
        $reset->approved_until = $until ?? now()->addHours(24);
        $reset->save();

        return $reset;
    }

    /*
    |--------------------------------------------------------------------------
    | Layer 3: the database invariant
    |--------------------------------------------------------------------------
    */

    public function test_a_second_active_device_for_one_user_is_refused_by_the_database(): void
    {
        $user = $this->employeeUser();
        $this->device($user);

        // The unique index on active_user_id is the layer that cannot be argued with. Even if
        // every check above it were bypassed, the row simply does not go in.
        $this->expectException(QueryException::class);

        $this->device($user);
    }

    public function test_revoking_frees_the_binding_slot(): void
    {
        $user = $this->employeeUser();
        $first = $this->device($user);

        $first->revoke('Testing.');

        $this->assertNull($first->fresh()->active_user_id);
        $this->assertNull(UserDevice::boundTo($user->id));

        // Freed, not merely emptied: the replacement can actually take the slot.
        $second = $this->device($user);
        $this->assertSame($second->id, UserDevice::boundTo($user->id)?->id);
    }

    public function test_two_users_may_each_hold_their_own_binding(): void
    {
        $a = $this->employeeUser();
        $b = $this->employeeUser();

        $this->device($a);
        $this->device($b);

        // The index constrains one active device PER USER, not one across the whole system.
        $this->assertNotNull(UserDevice::boundTo($a->id));
        $this->assertNotNull(UserDevice::boundTo($b->id));
    }

    /*
    |--------------------------------------------------------------------------
    | Layer 2: the binding token
    |--------------------------------------------------------------------------
    */

    public function test_a_device_token_resolves_only_to_the_device_it_was_issued_for(): void
    {
        $tokens = app(DeviceTokenService::class);

        $first = $this->device($this->employeeUser());
        $second = $this->device($this->employeeUser());

        $token = $tokens->issue($first);

        $this->assertSame($first->id, $tokens->resolve($token)?->id);
        $this->assertNotSame($second->id, $tokens->resolve($token)?->id);
    }

    public function test_the_plaintext_token_is_never_stored(): void
    {
        $device = $this->device($this->employeeUser());
        $token = app(DeviceTokenService::class)->issue($device);

        $this->assertNotSame($token, $device->fresh()->device_token_hash);
        $this->assertSame(hash('sha256', $token), $device->fresh()->device_token_hash);
    }

    public function test_a_token_belonging_to_a_revoked_device_resolves_to_nothing(): void
    {
        $tokens = app(DeviceTokenService::class);

        $device = $this->device($this->employeeUser());
        $token = $tokens->issue($device);

        $device->revoke('Testing.');

        // A retired phone's token must behave exactly like no token at all — otherwise a
        // revoked device could still identify itself.
        $this->assertNull($tokens->resolve($token));
    }

    public function test_a_forged_token_resolves_to_nothing(): void
    {
        $this->device($this->employeeUser());

        $this->assertNull(app(DeviceTokenService::class)->resolve('not-a-real-token'));
    }

    /*
    |--------------------------------------------------------------------------
    | Layer 1: the authenticator's exclude list
    |--------------------------------------------------------------------------
    */

    public function test_the_exclude_list_covers_every_active_credential_in_the_system(): void
    {
        $mine = $this->device($this->employeeUser());
        $theirs = $this->device($this->employeeUser());
        $this->device($this->employeeUser())->revoke('Testing.');

        $descriptors = app(CredentialSourceRepository::class)->allActiveDescriptors();
        $ids = array_map(fn ($d) => $this->encodeId($d->id), $descriptors);

        // Another user's credential MUST be present. That is the whole mechanism: a phone
        // already linked to somebody else aborts the ceremony itself, before this server is
        // asked anything.
        $this->assertContains($theirs->credential_id, $ids);
        $this->assertContains($mine->credential_id, $ids);

        // And a revoked credential must NOT be, or a replaced phone could never be re-linked.
        $this->assertCount(2, $descriptors);
    }

    public function test_the_per_user_allow_list_stays_scoped_to_that_user(): void
    {
        $mine = $this->device($user = $this->employeeUser());
        $theirs = $this->device($this->employeeUser());

        // Assertion is a different question from registration: allowCredentials must NOT leak
        // other people's credentials, or the check-in page would offer them.
        $ids = array_map(
            fn ($d) => $this->encodeId($d->id),
            app(CredentialSourceRepository::class)->descriptorsFor($user),
        );

        $this->assertSame([$mine->credential_id], $ids);
        $this->assertNotContains($theirs->credential_id, $ids);
    }

    public function test_the_exclude_list_is_capped(): void
    {
        config()->set('webauthn.exclude_limit', 1);

        $this->device($this->employeeUser());
        $this->device($this->employeeUser());

        $this->assertCount(1, app(CredentialSourceRepository::class)->allActiveDescriptors());
    }

    /*
    |--------------------------------------------------------------------------
    | The registration gate
    |--------------------------------------------------------------------------
    */

    public function test_a_first_ever_registration_needs_no_reset_approval(): void
    {
        $this->assertTrue($this->employeeUser()->can('create', UserDevice::class));
    }

    public function test_a_user_who_already_has_a_device_cannot_register_another(): void
    {
        $user = $this->employeeUser();
        $this->device($user);

        $this->assertFalse($user->can('create', UserDevice::class));

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/devices/register/options')
            ->assertForbidden();
    }

    public function test_a_user_whose_device_was_revoked_still_cannot_register_without_an_approved_reset(): void
    {
        $user = $this->employeeUser();
        $this->device($user)->revoke('Lost.');

        // The slot is free, but freeing it is not the same as being allowed to fill it. Without
        // this, an Admin revoking a suspicious device would hand its owner a free re-link.
        $this->assertFalse($user->can('create', UserDevice::class));
    }

    public function test_an_approved_reset_permits_exactly_one_registration(): void
    {
        $user = $this->employeeUser();
        $this->device($user)->revoke('Replaced.');

        $reset = $this->approvedReset($user);

        $this->assertTrue($user->can('create', UserDevice::class));

        // Spending it closes the window at once, so the same approval cannot buy a second
        // device tomorrow.
        $reset->forceFill(['used_at' => now()])->save();

        $this->assertFalse($user->fresh()->can('create', UserDevice::class));
    }

    public function test_an_expired_reset_approval_does_not_permit_registration(): void
    {
        $user = $this->employeeUser();
        $this->device($user)->revoke('Replaced.');

        $this->approvedReset($user, now()->subMinute());

        // An approval left open indefinitely is a standing licence to enroll whatever handset
        // is nearest; the deadline is the point of it.
        $this->assertFalse($user->can('create', UserDevice::class));
    }

    public function test_an_inactive_employee_still_cannot_register(): void
    {
        $this->assertFalse($this->employeeUser('inactive')->can('create', UserDevice::class));
    }

    /*
    |--------------------------------------------------------------------------
    | A handset already bound to somebody else
    |--------------------------------------------------------------------------
    */

    public function test_registering_from_a_handset_bound_to_another_account_is_refused_and_audited(): void
    {
        $owner = $this->employeeUser();
        $intruder = $this->employeeUser();

        $token = app(DeviceTokenService::class)->issue($this->device($owner));

        // Reaching verify at all means the exclude list was bypassed — either the passkey was
        // deleted and re-created, or the client is not behaving. The token is what still gives
        // the handset away.
        $this->actingAs($intruder)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->withHeader(config('device.token_header'), $token)
            ->postJson('/devices/register/verify', [
                'device_name' => 'Shared Phone',
                'credential' => ['id' => 'x'],
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('audit_logs', ['action' => 'device.link_conflict']);
        $this->assertSame(1, UserDevice::where('status', DeviceStatus::Active)->count());
    }

    public function test_a_handset_presenting_its_own_accounts_token_is_not_treated_as_a_conflict(): void
    {
        $user = $this->employeeUser();
        $token = app(DeviceTokenService::class)->issue($this->device($user));

        // Same account, same phone: re-registration is refused by the POLICY (the slot is
        // taken), not misreported as somebody else's device.
        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->withHeader(config('device.token_header'), $token)
            ->postJson('/devices/register/verify', [
                'device_name' => 'Same Phone',
                'credential' => ['id' => 'x'],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'device.link_conflict']);
    }

    private function encodeId(string $rawId): string
    {
        return rtrim(strtr(base64_encode($rawId), '+/', '-_'), '=');
    }
}
