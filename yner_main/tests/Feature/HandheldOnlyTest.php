<?php

namespace Tests\Feature;

use App\Enums\CheckInRejection;
use App\Enums\CheckInResult;
use App\Enums\DeviceStatus;
use App\Enums\RoleName;
use App\Models\Employee;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\WorkLocation;
use App\Models\WorkSchedule;
use App\Services\DeviceTokenService;
use App\Services\WebAuthn\CredentialSourceRepository;
use App\Services\WebAuthnService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakeWebAuthnService;
use Tests\TestCase;

/**
 * Phones and tablets only.
 *
 * Attendance assumes a handset: a real GPS fix, and an authenticator carried by one person.
 * A desktop offers neither honestly — its "location" is the building's router and its
 * authenticator is shared with whoever can unlock the machine — so both check-in and device
 * registration refuse it.
 *
 * These tests deliberately drive the two surfaces separately. Hiding the button (the Inertia
 * props) and refusing the request (the endpoints) are different claims, and a UI-only rule is
 * no rule at all when the endpoint is one fetch() away.
 */
class HandheldOnlyTest extends TestCase
{
    use RefreshDatabase;

    private float $officeLat = -6.7924;

    private float $officeLon = 39.2083;

    private FakeWebAuthnService $webauthn;

    private ?string $deviceToken = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        config()->set('webauthn.rp_id', 'eapms.test');

        $this->webauthn = new FakeWebAuthnService(app(CredentialSourceRepository::class));
        $this->app->instance(WebAuthnService::class, $this->webauthn);

        // Inside the check-in window of the 08:00-17:00 schedule the fixture uses.
        Carbon::setTestNow(Carbon::parse('2026-06-22 08:05:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | Check-in
    |--------------------------------------------------------------------------
    */

    public function test_a_check_in_from_a_desktop_is_refused(): void
    {
        $user = $this->employeeUser();

        $this->checkIn($user, agent: self::DESKTOP_USER_AGENT)
            ->assertStatus(422)
            ->assertJson(['reason' => CheckInRejection::UnsupportedDevice->value]);

        // Refused loudly, not silently: an employee working around the rule from their desk
        // is exactly what an Admin should be able to find in the log afterwards.
        $this->assertDatabaseHas('mobile_check_ins', [
            'user_id' => $user->id,
            'result' => CheckInResult::Rejected->value,
            'rejection_reason' => CheckInRejection::UnsupportedDevice->value,
        ]);

        $this->assertDatabaseCount('raw_attendance_logs', 0);
    }

    public function test_a_check_in_from_a_phone_is_accepted(): void
    {
        $user = $this->employeeUser();

        $this->checkIn($user)->assertOk();

        $this->assertDatabaseHas('mobile_check_ins', [
            'user_id' => $user->id,
            'result' => CheckInResult::Accepted->value,
        ]);
    }

    public function test_a_check_in_from_a_tablet_is_accepted(): void
    {
        // Tablets count as handhelds: they are carried, personal, and have real GPS.
        $agent = 'Mozilla/5.0 (iPad; CPU OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1';

        $this->checkIn($this->employeeUser(), agent: $agent)->assertOk();
    }

    public function test_an_ipad_in_desktop_mode_is_accepted(): void
    {
        // iPadOS sends a Macintosh User-Agent by default. Without the touch-points header this
        // is indistinguishable from an iMac, and would be refused.
        $agent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15';

        $this->checkIn($this->employeeUser(), agent: $agent, headers: ['X-Client-Touch-Points' => '5'])
            ->assertOk();
    }

    public function test_a_desktop_is_allowed_when_the_rule_is_switched_off(): void
    {
        // The escape hatch for a deployment piloting the channel on shared office machines.
        config()->set('attendance.mobile.require_handheld', false);

        $this->checkIn($this->employeeUser(), agent: self::DESKTOP_USER_AGENT)->assertOk();
    }

    public function test_the_assertion_challenge_is_refused_on_a_desktop(): void
    {
        // Refused before the ceremony, so a desktop user is not asked for a fingerprint to
        // produce a punch that would be thrown away a moment later.
        $this->actingAs($this->employeeUser())
            ->withHeader('User-Agent', self::DESKTOP_USER_AGENT)
            ->postJson('/check-in/assertion-options')
            ->assertStatus(422)
            ->assertJson(['reason' => CheckInRejection::UnsupportedDevice->value]);
    }

    public function test_the_check_in_page_tells_a_desktop_user_why_it_is_disabled(): void
    {
        $this->actingAs($this->employeeUser())
            ->withHeader('User-Agent', self::DESKTOP_USER_AGENT)
            ->get('/check-in')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('handheld', false));

        // Spelled out rather than relying on the suite default: withHeader() persists for the
        // rest of the test, so the desktop UA above is still in force here.
        $this->actingAs($this->employeeUser())
            ->withHeader('User-Agent', self::PHONE_USER_AGENT)
            ->get('/check-in')
            ->assertInertia(fn ($page) => $page->where('handheld', true));
    }

    /*
    |--------------------------------------------------------------------------
    | Device registration
    |--------------------------------------------------------------------------
    */

    public function test_registration_options_are_refused_on_a_desktop(): void
    {
        $user = $this->employeeUser(withDevice: false);

        $this->actingAs($user)
            ->withHeader('User-Agent', self::DESKTOP_USER_AGENT)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/devices/register/options')
            ->assertStatus(422);

        // No device row, and an audit trail of the attempt — there is no mobile_check_ins row
        // to carry it here, so the audit log is the only record.
        $this->assertDatabaseCount('user_devices', 0);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'device.registration_refused',
        ]);
    }

    public function test_registration_verify_is_refused_on_a_desktop(): void
    {
        // Checked on both legs of the ceremony: they are separate requests, and this is the
        // one that would create the binding.
        $user = $this->employeeUser(withDevice: false);

        $this->actingAs($user)
            ->withHeader('User-Agent', self::PHONE_USER_AGENT)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/devices/register/options')
            ->assertOk();

        $this->actingAs($user)
            ->withHeader('User-Agent', self::DESKTOP_USER_AGENT)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/devices/register/verify', [
                'device_name' => 'Office PC',
                'credential' => ['id' => 'cred', 'response' => []],
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('user_devices', 0);
    }

    public function test_the_devices_page_hides_registration_on_a_desktop(): void
    {
        $user = $this->employeeUser(withDevice: false);

        $this->actingAs($user)
            ->withHeader('User-Agent', self::DESKTOP_USER_AGENT)
            ->get('/devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('handheld', false)
                ->where('actions.register', false));

        $this->actingAs($user)
            ->withHeader('User-Agent', self::PHONE_USER_AGENT)
            ->get('/devices')
            ->assertInertia(fn ($page) => $page
                ->where('handheld', true)
                ->where('actions.register', true));
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    private function employeeUser(bool $withDevice = true): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleName::Employee->value);

        $schedule = WorkSchedule::firstOrCreate(
            ['name' => 'Standard'],
            ['start_time' => '08:00', 'end_time' => '17:00', 'grace_period_minutes' => 15],
        );

        $location = WorkLocation::firstOrCreate(
            ['name' => 'Head Office'],
            [
                'address' => 'Dar es Salaam',
                'latitude' => $this->officeLat,
                'longitude' => $this->officeLon,
                'radius_meters' => 150,
                'is_active' => true,
            ],
        );

        Employee::create([
            'user_id' => $user->id,
            'employee_code' => 'EMP-'.fake()->unique()->numberBetween(1000, 99999),
            'first_name' => 'Amina',
            'last_name' => 'Juma',
            'status' => 'active',
            'work_schedule_id' => $schedule->id,
            'work_location_id' => $location->id,
        ]);

        $this->deviceToken = null;
        $this->webauthn->device = null;

        if ($withDevice) {
            $this->webauthn->device = UserDevice::create([
                'user_id' => $user->id,
                'credential_id' => 'cred-'.fake()->unique()->numberBetween(1, 100000),
                'public_key' => '{}',
                'sign_count' => 0,
                'rp_id' => 'eapms.test',
                'device_name' => 'Test Phone',
                'status' => DeviceStatus::Active,
            ]);

            $this->deviceToken = app(DeviceTokenService::class)->issue($this->webauthn->device);
        }

        return $user->fresh();
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function checkIn(User $user, ?string $agent = null, array $headers = [])
    {
        return $this->actingAs($user)
            ->withHeaders($headers + [
                'User-Agent' => $agent ?? self::PHONE_USER_AGENT,
            ] + ($this->deviceToken ? [config('device.token_header') => $this->deviceToken] : []))
            ->postJson('/check-in', [
                'direction' => 'in',
                'latitude' => $this->officeLat,
                'longitude' => $this->officeLon,
                'accuracy_meters' => 12,
                'credential' => ['id' => 'cred', 'response' => []],
            ]);
    }
}
