<?php

namespace Tests\Feature;

use App\Enums\AttendanceSource;
use App\Enums\CheckInDirection;
use App\Enums\CheckInRejection;
use App\Enums\CheckInResult;
use App\Enums\DeviceStatus;
use App\Enums\RoleName;
use App\Jobs\ComputeAttendanceForDate;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\MobileCheckIn;
use App\Models\RawAttendanceLog;
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
use Illuminate\Support\Facades\Bus;
use Tests\Support\FakeWebAuthnService;
use Tests\TestCase;

/**
 * The mobile check-in gate chain.
 *
 * One test per rejection reason, because each gate is a separate security claim and a chain
 * that silently stops enforcing one of them would still pass a single happy-path test. The
 * WebAuthn ceremony itself is faked (see FakeWebAuthnService) so these stay focused on the
 * business rules; the hard block is still proven, by making the fake fail.
 */
class MobileCheckInTest extends TestCase
{
    use RefreshDatabase;

    /** The office. All fixtures are positioned relative to this point. */
    private float $officeLat = -6.7924;

    private float $officeLon = 39.2083;

    private FakeWebAuthnService $webauthn;

    /**
     * The plaintext binding token of the fixture device, mirrored on every request.
     *
     * Every gate after 2b assumes the handset proved itself, so without this the whole suite
     * would stop at DeviceMismatch and never reach the geofence or schedule rules it exists
     * to test.
     */
    private ?string $deviceToken = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        config()->set('webauthn.rp_id', 'eapms.test');

        $this->webauthn = new FakeWebAuthnService(app(CredentialSourceRepository::class));
        $this->app->instance(WebAuthnService::class, $this->webauthn);

        // Inside the check-in window for the 08:00–17:00 schedule the fixtures use.
        Carbon::setTestNow(Carbon::parse('2026-06-22 08:05:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    private function location(array $overrides = []): WorkLocation
    {
        return WorkLocation::create($overrides + [
            'name' => 'Head Office',
            'address' => 'Dar es Salaam',
            'latitude' => $this->officeLat,
            'longitude' => $this->officeLon,
            'radius_meters' => 150,
            'is_active' => true,
        ]);
    }

    private function employeeUser(array $employeeOverrides = [], bool $withDevice = true): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleName::Employee->value);

        $schedule = WorkSchedule::firstOrCreate(
            ['name' => 'Standard'],
            ['start_time' => '08:00', 'end_time' => '17:00', 'grace_period_minutes' => 15],
        );

        Employee::create($employeeOverrides + [
            'user_id' => $user->id,
            'employee_code' => 'EMP-'.fake()->unique()->numberBetween(1000, 99999),
            'first_name' => 'Amina',
            'last_name' => 'Juma',
            'status' => 'active',
            'work_schedule_id' => $schedule->id,
            'work_location_id' => $this->location()->id,
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
     * A payload standing at the office with a good fix, unless told otherwise.
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'direction' => 'in',
            'latitude' => $this->officeLat,
            'longitude' => $this->officeLon,
            'accuracy_meters' => 12,
            'credential' => ['id' => 'cred', 'response' => []],
        ];
    }

    private function checkIn(User $user, array $overrides = [])
    {
        return $this->actingAs($user)
            ->withHeaders($this->deviceToken ? [config('device.token_header') => $this->deviceToken] : [])
            ->postJson('/check-in', $this->payload($overrides));
    }

    private function assertRejected($response, CheckInRejection $reason): void
    {
        $response->assertStatus(422)->assertJson(['reason' => $reason->value]);

        // The refusal is persisted, not just returned. That is the whole control on this
        // channel: an attacker probing the geofence leaves a trail either way.
        $this->assertDatabaseHas('mobile_check_ins', [
            'result' => CheckInResult::Rejected->value,
            'rejection_reason' => $reason->value,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Happy path
    |--------------------------------------------------------------------------
    */

    public function test_a_valid_check_in_writes_a_mobile_punch_into_raw_attendance_logs(): void
    {
        $user = $this->employeeUser();

        $this->checkIn($user)->assertOk()->assertJson(['direction' => 'in', 'flagged' => false]);

        $log = RawAttendanceLog::first();
        $this->assertNotNull($log);
        $this->assertSame(RawAttendanceLog::MOBILE_SERIAL, $log->device_serial);
        $this->assertSame(AttendanceSource::Mobile, $log->source);
        $this->assertSame($user->employee->id, $log->employee_id);

        $checkIn = MobileCheckIn::first();
        $this->assertSame(CheckInResult::Accepted, $checkIn->result);
        $this->assertTrue($checkIn->webauthn_verified);
        $this->assertTrue($checkIn->within_geofence);
        $this->assertSame($log->id, $checkIn->raw_attendance_log_id);
        // Standing at the centre: the server computed a distance, and it is ~0.
        $this->assertSame(0, $checkIn->distance_meters);
    }

    public function test_punched_at_is_the_server_clock_and_ignores_any_client_timestamp(): void
    {
        $user = $this->employeeUser();

        // A client claiming it punched at 08:00 when the server clock says 08:05. Accepting
        // this would turn every late arrival into an on-time one.
        $this->checkIn($user, ['punched_at' => '2026-06-22 08:00:00'])->assertOk();

        $this->assertSame('2026-06-22 08:05:00', RawAttendanceLog::first()->punched_at->toDateTimeString());
    }

    public function test_an_accepted_check_in_is_audited(): void
    {
        $user = $this->employeeUser();

        $this->checkIn($user)->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'auditable_type' => MobileCheckIn::class,
            'action' => 'mobile.check-in',
        ]);

        $this->assertSame('Head Office', AuditLog::first()->new_values['work_location']);
    }

    public function test_an_accepted_check_in_dispatches_the_compute_job(): void
    {
        Bus::fake();

        $this->checkIn($this->employeeUser())->assertOk();

        Bus::assertDispatched(
            ComputeAttendanceForDate::class,
            fn (ComputeAttendanceForDate $job) => $job->date === '2026-06-22',
        );
    }

    public function test_check_out_after_a_check_in_succeeds(): void
    {
        $user = $this->employeeUser();

        $this->checkIn($user)->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-06-22 17:05:00'));

        $this->checkIn($user, ['direction' => 'out'])->assertOk()->assertJson(['direction' => 'out']);

        $this->assertSame(2, RawAttendanceLog::count());
        $this->assertSame(2, MobileCheckIn::accepted()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | One test per gate
    |--------------------------------------------------------------------------
    */

    public function test_a_user_with_no_employee_record_cannot_check_in(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RoleName::Employee->value);

        // The policy refuses before the service is ever reached — there is no employee for a
        // rejection row to belong to, so this is a 403 rather than a recorded attempt.
        $this->actingAs($user)->postJson('/check-in', $this->payload())->assertForbidden();
        $this->assertSame(0, MobileCheckIn::count());
    }

    public function test_an_inactive_employee_cannot_check_in(): void
    {
        $user = $this->employeeUser(['status' => 'inactive']);

        $this->actingAs($user)->postJson('/check-in', $this->payload())->assertForbidden();
    }

    public function test_a_check_in_without_a_valid_assertion_is_rejected(): void
    {
        $user = $this->employeeUser();
        $this->webauthn->shouldFail = true;

        $this->assertRejected($this->checkIn($user), CheckInRejection::WebauthnFailed);

        // Hard block: nothing reached the attendance pipeline.
        $this->assertSame(0, RawAttendanceLog::count());
    }

    public function test_a_check_in_with_no_credential_at_all_is_rejected(): void
    {
        $user = $this->employeeUser();
        $this->webauthn->shouldFail = true;

        $this->assertRejected($this->checkIn($user, ['credential' => null]), CheckInRejection::WebauthnFailed);
    }

    /*
    |--------------------------------------------------------------------------
    | The device binding: one account, one device
    |--------------------------------------------------------------------------
    */

    public function test_an_account_with_no_linked_device_cannot_check_in(): void
    {
        $user = $this->employeeUser(withDevice: false);

        // The assertion is faked as succeeding against a device that does not exist, so this
        // isolates gate 2b from gate 2: even a "verified" request is refused when the account
        // holds no binding.
        $this->webauthn->device = UserDevice::create([
            'user_id' => User::factory()->create()->id,
            'credential_id' => 'orphan-cred',
            'public_key' => '{}',
            'rp_id' => 'eapms.test',
            'device_name' => 'Someone Else Phone',
        ]);

        $this->assertRejected($this->checkIn($user), CheckInRejection::NoLinkedDevice);
        $this->assertSame(0, RawAttendanceLog::count());
    }

    public function test_an_assertion_from_a_device_that_is_not_the_bound_one_is_rejected(): void
    {
        $user = $this->employeeUser();

        // A second credential on the same account: impossible to create through the app, but
        // exactly what a leaked or replayed credential would look like arriving here.
        $this->webauthn->device = UserDevice::create([
            'user_id' => $user->id,
            'credential_id' => 'stale-cred',
            'public_key' => '{}',
            'rp_id' => 'eapms.test',
            'device_name' => 'Old Phone',
            'status' => DeviceStatus::Revoked,
        ]);

        $this->assertRejected($this->checkIn($user), CheckInRejection::DeviceMismatch);
        $this->assertSame(0, RawAttendanceLog::count());

        // Not an ordinary refusal: a mismatch is a plausible account-sharing attempt, so it is
        // flagged for an Admin rather than filed away silently.
        $this->assertTrue(MobileCheckIn::first()->flagged);
    }

    public function test_a_check_in_without_the_device_binding_token_is_rejected(): void
    {
        $user = $this->employeeUser();

        // The credential verifies and belongs to the bound device — this is the case WebAuthn
        // alone cannot catch, where the passkey has reached a second handset.
        $this->deviceToken = null;

        $this->assertRejected($this->checkIn($user), CheckInRejection::DeviceMismatch);
        $this->assertSame(0, RawAttendanceLog::count());
    }

    public function test_a_binding_token_belonging_to_another_device_is_rejected(): void
    {
        $user = $this->employeeUser();

        $other = UserDevice::create([
            'user_id' => User::factory()->create()->id,
            'credential_id' => 'other-cred',
            'public_key' => '{}',
            'rp_id' => 'eapms.test',
            'device_name' => 'Colleague Phone',
        ]);

        $this->deviceToken = app(DeviceTokenService::class)->issue($other);

        $this->assertRejected($this->checkIn($user), CheckInRejection::DeviceMismatch);
    }

    public function test_a_revoked_device_holds_no_binding_slot(): void
    {
        $user = $this->employeeUser();

        $this->webauthn->device->revoke('Testing.');

        // Revocation nulls active_user_id, so the account is back to having no linked device
        // at all rather than a mismatched one.
        $this->assertNull(UserDevice::boundTo($user->id));
        $this->assertRejected($this->checkIn($user), CheckInRejection::NoLinkedDevice);
    }

    public function test_a_coarse_gps_fix_is_rejected(): void
    {
        $user = $this->employeeUser();

        // 500m accuracy is a cell-tower or IP-derived fix; it would clear a 150m geofence by
        // luck rather than by the phone actually being there.
        $this->assertRejected(
            $this->checkIn($user, ['accuracy_meters' => 500]),
            CheckInRejection::LowGpsAccuracy,
        );
    }

    public function test_an_employee_with_no_work_location_is_rejected(): void
    {
        $user = $this->employeeUser(['work_location_id' => null]);

        // Fail closed: no location on the employee and none on a department means there is
        // nothing to measure against, so the check-in is refused rather than waved through.
        $this->assertRejected($this->checkIn($user), CheckInRejection::NoWorkLocation);
    }

    public function test_an_inactive_work_location_is_treated_as_no_location(): void
    {
        $user = $this->employeeUser();
        $user->employee->workLocation->update(['is_active' => false]);

        $this->assertRejected($this->checkIn($user), CheckInRejection::NoWorkLocation);
    }

    public function test_a_department_location_is_used_when_the_employee_has_none(): void
    {
        $location = $this->location(['name' => 'Branch Office']);
        $department = Department::create(['name' => 'Field Ops', 'work_location_id' => $location->id]);

        $user = $this->employeeUser(['work_location_id' => null, 'department_id' => $department->id]);

        $this->checkIn($user)->assertOk();
        $this->assertSame($location->id, MobileCheckIn::first()->work_location_id);
    }

    public function test_a_check_in_outside_the_geofence_is_rejected_but_records_the_real_distance(): void
    {
        $user = $this->employeeUser();

        // ~1.1km east: 0.01° of longitude near the equator.
        $response = $this->checkIn($user, ['longitude' => $this->officeLon + 0.01]);

        $this->assertRejected($response, CheckInRejection::OutsideGeofence);

        $attempt = MobileCheckIn::first();
        $this->assertFalse($attempt->within_geofence);
        $this->assertGreaterThan(1000, $attempt->distance_meters);
        // Returned to the client so the employee can walk closer instead of calling HR.
        $response->assertJsonPath('distance_meters', $attempt->distance_meters);
        $this->assertSame(0, RawAttendanceLog::count());
    }

    public function test_an_employee_with_no_work_schedule_is_rejected(): void
    {
        $user = $this->employeeUser();
        $user->employee->update(['work_schedule_id' => null]);

        $this->assertRejected($this->checkIn($user), CheckInRejection::NoWorkSchedule);
    }

    public function test_a_check_in_outside_the_schedule_window_is_rejected(): void
    {
        $user = $this->employeeUser();

        // Window opens 06:00 (08:00 less the 120-minute early allowance). 03:00 is far outside.
        Carbon::setTestNow(Carbon::parse('2026-06-22 03:00:00'));

        $this->assertRejected($this->checkIn($user), CheckInRejection::OutsideScheduleWindow);
    }

    public function test_a_second_check_in_on_the_same_day_is_rejected(): void
    {
        $user = $this->employeeUser();

        $this->checkIn($user)->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-06-22 09:00:00'));

        $this->assertRejected($this->checkIn($user), CheckInRejection::AlreadyCheckedIn);
        $this->assertSame(1, RawAttendanceLog::count());
    }

    public function test_checking_out_without_checking_in_is_rejected(): void
    {
        $user = $this->employeeUser();

        Carbon::setTestNow(Carbon::parse('2026-06-22 17:00:00'));

        $this->assertRejected($this->checkIn($user, ['direction' => 'out']), CheckInRejection::NoCheckIn);
    }

    public function test_a_second_check_out_on_the_same_day_is_rejected(): void
    {
        $user = $this->employeeUser();

        $this->checkIn($user)->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-06-22 17:00:00'));
        $this->checkIn($user, ['direction' => 'out'])->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-06-22 17:30:00'));
        $this->assertRejected($this->checkIn($user, ['direction' => 'out']), CheckInRejection::AlreadyCheckedOut);
    }

    public function test_a_punch_too_soon_after_the_previous_one_is_rejected(): void
    {
        $user = $this->employeeUser();

        // Widen the check-out window so it overlaps the check-in one. With the default
        // 08:00-17:00 schedule the two windows are hours apart and the debounce can never
        // fire — this makes the gate reachable rather than testing a different one by accident.
        config()->set('attendance.mobile.check_out_early_minutes', 600);

        $this->checkIn($user)->assertOk();

        // 20 seconds later, under the 60-second debounce — a double tap, not a second punch.
        Carbon::setTestNow(Carbon::parse('2026-06-22 08:05:20'));

        $this->assertRejected($this->checkIn($user, ['direction' => 'out']), CheckInRejection::TooSoon);
    }

    public function test_a_duplicate_punch_at_the_same_second_is_refused_not_a_server_error(): void
    {
        $user = $this->employeeUser();
        $employee = $user->employee;

        // A punch already exists at this exact second — the dedupe unique index will refuse a
        // second one. That must surface as a friendly 422, never a 500.
        RawAttendanceLog::create([
            'device_serial' => RawAttendanceLog::MOBILE_SERIAL,
            'device_user_id' => (string) $employee->id,
            'employee_id' => $employee->id,
            'source' => AttendanceSource::Mobile,
            'punched_at' => Carbon::parse('2026-06-22 08:05:00'),
        ]);

        $this->assertRejected($this->checkIn($user), CheckInRejection::DuplicatePunch);
    }

    /*
    |--------------------------------------------------------------------------
    | Impossible travel — flagged, never rejected
    |--------------------------------------------------------------------------
    */

    public function test_impossible_travel_is_flagged_but_still_accepted(): void
    {
        $user = $this->employeeUser();
        $employee = $user->employee;

        // A previous accepted punch 500km away, one minute ago — 30,000 km/h implied.
        MobileCheckIn::create([
            'employee_id' => $employee->id,
            'user_id' => $user->id,
            'work_date' => '2026-06-21',
            'direction' => CheckInDirection::Out,
            'punched_at' => Carbon::parse('2026-06-22 08:04:00'),
            'latitude' => $this->officeLat + 4.5,
            'longitude' => $this->officeLon,
            'accuracy_meters' => 10,
            'result' => CheckInResult::Accepted,
        ]);

        $this->checkIn($user)->assertOk()->assertJson(['flagged' => true]);

        $accepted = MobileCheckIn::whereDate('work_date', '2026-06-22')->first();
        $this->assertTrue($accepted->flagged);
        $this->assertNotNull($accepted->flag_reason);
        // Accepted is the point: a real employee who flew, or whose last fix was bad, keeps
        // their attendance and an Admin gets to judge it.
        $this->assertSame(CheckInResult::Accepted, $accepted->result);
        $this->assertSame(1, RawAttendanceLog::count());
    }

    public function test_normal_movement_between_punches_is_not_flagged(): void
    {
        $user = $this->employeeUser();

        $this->checkIn($user)->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-06-22 17:00:00'));
        $this->checkIn($user, ['direction' => 'out'])->assertOk()->assertJson(['flagged' => false]);
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization and validation
    |--------------------------------------------------------------------------
    */

    public function test_guests_cannot_check_in(): void
    {
        $this->postJson('/check-in', $this->payload())->assertUnauthorized();
    }

    public function test_the_check_in_page_loads_for_an_employee(): void
    {
        $this->actingAs($this->employeeUser())->get('/check-in')->assertOk();
    }

    public function test_the_check_in_page_loads_for_a_user_with_no_employee_record(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RoleName::Employee->value);

        // No employee is a state the page must explain, not crash on.
        $this->actingAs($user)->get('/check-in')->assertOk();
    }

    public function test_coordinates_outside_the_valid_range_are_rejected_by_validation(): void
    {
        $user = $this->employeeUser();

        $this->actingAs($user)
            ->postJson('/check-in', $this->payload(['latitude' => 120]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('latitude');
    }

    public function test_an_unknown_direction_is_rejected_by_validation(): void
    {
        $this->actingAs($this->employeeUser())
            ->postJson('/check-in', $this->payload(['direction' => 'sideways']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('direction');
    }
}
