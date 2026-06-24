<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\BiometricDevice;
use App\Models\DeviceEnrollment;
use App\Models\Employee;
use App\Models\RawAttendanceLog;
use App\Models\WorkSchedule;
use App\Services\AttendanceCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AttendanceCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private string $date = '2026-06-22'; // Monday

    private string $serial = 'STUB-001';

    private function makeEmployee(string $code = 'EMP-0001'): Employee
    {
        $schedule = WorkSchedule::firstOrCreate(
            ['name' => 'Standard'],
            ['start_time' => '08:00', 'end_time' => '17:00', 'grace_period_minutes' => 15],
        );

        $employee = Employee::create([
            'employee_code' => $code,
            'first_name' => 'Test',
            'last_name' => 'Person',
            'status' => 'active',
            'work_schedule_id' => $schedule->id,
        ]);

        $device = BiometricDevice::firstOrCreate(
            ['serial' => $this->serial],
            ['name' => 'Door', 'type' => 'stub', 'status' => 'active'],
        );

        DeviceEnrollment::create([
            'biometric_device_id' => $device->id,
            'employee_id' => $employee->id,
            'device_user_id' => $code,
        ]);

        return $employee;
    }

    private function punch(string $code, string $time): void
    {
        RawAttendanceLog::create([
            'device_serial' => $this->serial,
            'device_user_id' => $code,
            'punched_at' => Carbon::parse("{$this->date} {$time}"),
        ]);
    }

    private function calculator(): AttendanceCalculator
    {
        return app(AttendanceCalculator::class);
    }

    public function test_on_time_employee_is_present(): void
    {
        $employee = $this->makeEmployee();
        $this->punch('EMP-0001', '08:00');
        $this->punch('EMP-0001', '17:00');

        $this->calculator()->computeForDate(Carbon::parse($this->date));

        $record = AttendanceRecord::where('employee_id', $employee->id)->first();
        $this->assertSame(AttendanceStatus::Present, $record->status);
        $this->assertSame(0, $record->late_minutes);
        $this->assertSame(540, $record->worked_minutes); // 08:00 → 17:00
    }

    public function test_late_arrival_is_flagged_with_minutes(): void
    {
        $employee = $this->makeEmployee();
        // Grace ends 08:15; arriving 08:30 is 15 minutes late.
        $this->punch('EMP-0001', '08:30');
        $this->punch('EMP-0001', '17:00');

        $this->calculator()->computeForDate(Carbon::parse($this->date));

        $record = AttendanceRecord::where('employee_id', $employee->id)->first();
        $this->assertSame(AttendanceStatus::Late, $record->status);
        $this->assertSame(15, $record->late_minutes);
        $this->assertSame(510, $record->worked_minutes);
    }

    public function test_arrival_within_grace_is_not_late(): void
    {
        $employee = $this->makeEmployee();
        $this->punch('EMP-0001', '08:10'); // within 15-minute grace
        $this->punch('EMP-0001', '17:00');

        $this->calculator()->computeForDate(Carbon::parse($this->date));

        $this->assertSame(AttendanceStatus::Present, AttendanceRecord::where('employee_id', $employee->id)->first()->status);
    }

    public function test_active_employee_with_no_punches_is_absent(): void
    {
        $employee = $this->makeEmployee();

        $this->calculator()->computeForDate(Carbon::parse($this->date));

        $record = AttendanceRecord::where('employee_id', $employee->id)->first();
        $this->assertSame(AttendanceStatus::Absent, $record->status);
        $this->assertNull($record->first_in);
        $this->assertNull($record->worked_minutes);
    }

    public function test_recompute_is_idempotent(): void
    {
        $employee = $this->makeEmployee();
        $this->punch('EMP-0001', '08:30');
        $this->punch('EMP-0001', '17:00');

        $this->calculator()->computeForDate(Carbon::parse($this->date));
        $this->calculator()->computeForDate(Carbon::parse($this->date));

        $this->assertSame(1, AttendanceRecord::where('employee_id', $employee->id)->count());
        $record = AttendanceRecord::where('employee_id', $employee->id)->first();
        $this->assertSame(15, $record->late_minutes);
    }

    public function test_manual_record_is_not_overwritten(): void
    {
        $employee = $this->makeEmployee();
        // A manual correction already exists for this day.
        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'work_date' => $this->date,
            'status' => AttendanceStatus::OfficialLeave,
            'is_manual' => true,
            'remarks' => 'Approved leave',
        ]);

        // New punches arrive that would otherwise compute to Late.
        $this->punch('EMP-0001', '09:30');
        $this->punch('EMP-0001', '17:00');

        $result = $this->calculator()->computeForDate(Carbon::parse($this->date));

        $record = AttendanceRecord::where('employee_id', $employee->id)->first();
        $this->assertSame(AttendanceStatus::OfficialLeave, $record->status);
        $this->assertTrue($record->is_manual);
        $this->assertSame(1, $result['skipped']);
    }
}
