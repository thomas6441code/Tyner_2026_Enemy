<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Models\BiometricDevice;
use App\Models\DeviceEnrollment;
use App\Models\Employee;
use App\Models\RawAttendanceLog;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ComputeAttendanceCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_computes_records_for_a_date(): void
    {
        $schedule = WorkSchedule::create([
            'name' => 'Standard', 'start_time' => '08:00', 'end_time' => '17:00', 'grace_period_minutes' => 15,
        ]);
        $employee = Employee::create([
            'employee_code' => 'EMP-0001', 'first_name' => 'Test', 'last_name' => 'Person',
            'status' => 'active', 'work_schedule_id' => $schedule->id,
        ]);
        $device = BiometricDevice::create(['name' => 'Door', 'type' => 'stub', 'serial' => 'STUB-001', 'status' => 'active']);
        DeviceEnrollment::create(['biometric_device_id' => $device->id, 'employee_id' => $employee->id, 'device_user_id' => 'EMP-0001']);

        RawAttendanceLog::create(['device_serial' => 'STUB-001', 'device_user_id' => 'EMP-0001', 'punched_at' => Carbon::parse('2026-06-22 08:05')]);
        RawAttendanceLog::create(['device_serial' => 'STUB-001', 'device_user_id' => 'EMP-0001', 'punched_at' => Carbon::parse('2026-06-22 17:00')]);

        $this->artisan('attendance:compute', ['date' => '2026-06-22'])->assertSuccessful();

        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $employee->id,
            'work_date' => '2026-06-22',
            'status' => AttendanceStatus::Present->value,
        ]);
    }
}
