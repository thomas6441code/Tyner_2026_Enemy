<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\DeviceStatus;
use App\Enums\RoleName;
use App\Jobs\ComputeAttendanceForDate;
use App\Models\AttendanceRecord;
use App\Models\BiometricDevice;
use App\Models\DeviceEnrollment;
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
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeWebAuthnService;
use Tests\TestCase;

/**
 * A mobile check-in must reach `attendance_records`, not merely `raw_attendance_logs`.
 *
 * Modelled on BiometricIngestComputeTest: the same claim for the second channel, plus the
 * cross-channel case that is the whole point of converging both sources on one table.
 */
class MobileCheckInComputeTest extends TestCase
{
    use RefreshDatabase;

    private float $lat = -6.7924;

    private float $lon = 39.2083;

    private FakeWebAuthnService $webauthn;

    private string $serial = 'STUB-001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        config()->set('webauthn.rp_id', 'eapms.test');

        $this->webauthn = new FakeWebAuthnService(app(CredentialSourceRepository::class));
        $this->app->instance(WebAuthnService::class, $this->webauthn);

        Carbon::setTestNow(Carbon::parse('2026-06-22 08:05:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private ?string $deviceToken = null;

    private function employeeUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleName::Employee->value);

        $schedule = WorkSchedule::firstOrCreate(
            ['name' => 'Standard'],
            ['start_time' => '08:00', 'end_time' => '17:00', 'grace_period_minutes' => 15],
        );

        $location = WorkLocation::create([
            'name' => 'Head Office',
            'latitude' => $this->lat,
            'longitude' => $this->lon,
            'radius_meters' => 150,
            'is_active' => true,
        ]);

        Employee::create([
            'user_id' => $user->id,
            'employee_code' => 'EMP-0001',
            'first_name' => 'Amina',
            'last_name' => 'Juma',
            'status' => 'active',
            'work_schedule_id' => $schedule->id,
            'work_location_id' => $location->id,
        ]);

        $this->webauthn->device = UserDevice::create([
            'user_id' => $user->id,
            'credential_id' => 'cred-1',
            'public_key' => '{}',
            'sign_count' => 0,
            'rp_id' => 'eapms.test',
            'device_name' => 'Test Phone',
            'status' => DeviceStatus::Active,
        ]);

        // The handset's binding token. Gate 2b refuses without it, so nothing in this file
        // would reach the attendance pipeline it exists to test.
        $this->deviceToken = app(DeviceTokenService::class)->issue($this->webauthn->device);

        return $user->fresh();
    }

    private function checkIn(User $user, string $direction = 'in')
    {
        return $this->actingAs($user)
            ->withHeader(config('device.token_header'), (string) $this->deviceToken)
            ->postJson('/check-in', [
                'direction' => $direction,
                'latitude' => $this->lat,
                'longitude' => $this->lon,
                'accuracy_meters' => 10,
                'credential' => ['id' => 'cred-1'],
            ]);
    }

    public function test_a_check_in_dispatches_a_compute_job_for_its_own_date(): void
    {
        Queue::fake();

        $this->checkIn($this->employeeUser())->assertOk();

        Queue::assertPushed(
            ComputeAttendanceForDate::class,
            fn (ComputeAttendanceForDate $job) => $job->date === '2026-06-22',
        );
    }

    public function test_a_mobile_check_in_reaches_attendance_records(): void
    {
        // Not faked: the job runs synchronously here, so this asserts the full path from a
        // phone tap to a row the employee's dashboard will show.
        $user = $this->employeeUser();

        $this->checkIn($user)->assertOk();

        $record = AttendanceRecord::where('employee_id', $user->employee->id)->first();
        $this->assertNotNull($record);
        $this->assertSame('08:05', $record->first_in->format('H:i'));
        // 08:05 is inside the 15-minute grace on an 08:00 start.
        $this->assertSame(AttendanceStatus::Present, $record->status);
    }

    /**
     * The cross-channel claim: a terminal punch and a mobile punch on the same day for the
     * same employee produce ONE record spanning both.
     */
    public function test_a_device_punch_and_a_mobile_check_out_merge_into_one_record(): void
    {
        $user = $this->employeeUser();
        $employee = $user->employee;

        $device = BiometricDevice::create([
            'serial' => $this->serial,
            'name' => 'Door',
            'type' => 'stub',
            'status' => 'active',
        ]);

        DeviceEnrollment::create([
            'biometric_device_id' => $device->id,
            'employee_id' => $employee->id,
            'device_user_id' => 'U001',
        ]);

        // Arrived through the terminal at 07:55, over the existing internal-secret API.
        $this->withHeaders(['X-Internal-Secret' => 'testing-secret'])
            ->postJson('/api/biometric/ingest', ['logs' => [
                ['device_user_id' => 'U001', 'punched_at' => '2026-06-22 07:55:00', 'device_serial' => $this->serial],
            ]])
            ->assertOk();

        // Left from the field at 17:10, through the mobile channel.
        // Checking out on the phone with no *mobile* check-in that day: allowed precisely
        // because the terminal punch already established that they arrived.
        Carbon::setTestNow(Carbon::parse('2026-06-22 17:10:00'));
        $this->checkIn($user, 'out')->assertOk();

        $records = AttendanceRecord::where('employee_id', $employee->id)->get();

        $this->assertCount(1, $records, 'The two channels must converge on a single daily record.');
        $this->assertSame('07:55', $records->first()->first_in->format('H:i'));
        $this->assertSame('17:10', $records->first()->last_out->format('H:i'));
    }
}
