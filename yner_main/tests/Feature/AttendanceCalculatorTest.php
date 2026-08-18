<?php

namespace Tests\Feature;

use App\Enums\AttendanceSource;
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

    /**
     * A punch as the mobile channel writes it: sentinel serial, employee_id already resolved.
     * Deliberately built by hand rather than through MobileCheckInService — this test is about
     * the calculator, not about the gate chain in front of it.
     */
    private function mobilePunch(Employee $employee, string $time): void
    {
        RawAttendanceLog::create([
            'device_serial' => RawAttendanceLog::MOBILE_SERIAL,
            'device_user_id' => (string) $employee->id,
            'employee_id' => $employee->id,
            'source' => AttendanceSource::Mobile,
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

    /*
    |--------------------------------------------------------------------------
    | Mobile channel
    |--------------------------------------------------------------------------
    |
    | Everything above is the biometric path and must keep passing untouched — that is the
    | actual regression guard for this feature. What follows proves the second channel resolves
    | through the same engine, and that the two merge rather than compete.
    |
    */

    public function test_a_mobile_only_punch_pair_computes_as_present(): void
    {
        $employee = $this->makeEmployee();
        $this->mobilePunch($employee, '08:00');
        $this->mobilePunch($employee, '17:00');

        $this->calculator()->computeForDate(Carbon::parse($this->date));

        $record = AttendanceRecord::where('employee_id', $employee->id)->first();
        $this->assertSame(AttendanceStatus::Present, $record->status);
        $this->assertSame(0, $record->late_minutes);
        $this->assertSame(540, $record->worked_minutes);
    }

    public function test_a_late_mobile_punch_is_flagged_late(): void
    {
        $employee = $this->makeEmployee();
        // Grace ends 08:15; 08:45 is 30 minutes late — the same rule the device channel gets,
        // because deriveAttributes() never learns which channel a punch came from.
        $this->mobilePunch($employee, '08:45');
        $this->mobilePunch($employee, '17:00');

        $this->calculator()->computeForDate(Carbon::parse($this->date));

        $record = AttendanceRecord::where('employee_id', $employee->id)->first();
        $this->assertSame(AttendanceStatus::Late, $record->status);
        $this->assertSame(30, $record->late_minutes);
    }

    /**
     * The integration test the whole two-channel design rests on.
     *
     * One employee, one day, one punch from each channel. If these ever produced two records,
     * or if one channel shadowed the other, every report and every AI aggregate downstream
     * would be wrong.
     */
    public function test_mobile_and_device_punches_for_one_employee_merge_into_one_record(): void
    {
        $employee = $this->makeEmployee();

        // Arrived at a terminal, left from the field.
        $this->punch('EMP-0001', '07:55');
        $this->mobilePunch($employee, '17:30');

        $this->calculator()->computeForDate(Carbon::parse($this->date));

        $this->assertSame(1, AttendanceRecord::where('employee_id', $employee->id)->count());

        $record = AttendanceRecord::where('employee_id', $employee->id)->first();
        $this->assertSame('07:55', $record->first_in->format('H:i'));
        $this->assertSame('17:30', $record->last_out->format('H:i'));
        $this->assertSame(AttendanceStatus::Present, $record->status);
        $this->assertSame(575, $record->worked_minutes);
    }

    public function test_a_mobile_punch_never_resolves_through_device_enrollments(): void
    {
        $employee = $this->makeEmployee();

        // A mobile row whose device_user_id happens to equal another employee's enrollment
        // code. The sentinel serial keeps the lookup key from ever matching, so the punch
        // belongs to its own employee_id and to nobody else.
        $other = $this->makeEmployee('EMP-0002');
        RawAttendanceLog::create([
            'device_serial' => RawAttendanceLog::MOBILE_SERIAL,
            'device_user_id' => 'EMP-0001',
            'employee_id' => $other->id,
            'source' => AttendanceSource::Mobile,
            'punched_at' => Carbon::parse("{$this->date} 09:00"),
        ]);

        $this->calculator()->computeForDate(Carbon::parse($this->date));

        $this->assertSame(
            AttendanceStatus::Absent,
            AttendanceRecord::where('employee_id', $employee->id)->first()->status,
        );
        $this->assertSame(
            '09:00',
            AttendanceRecord::where('employee_id', $other->id)->first()->first_in->format('H:i'),
        );
    }
}
