<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\PermissionStatus;
use App\Enums\PermissionType;
use App\Enums\RoleName;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\BiometricDevice;
use App\Models\DeviceEnrollment;
use App\Models\Employee;
use App\Models\PermissionRequest;
use App\Models\RawAttendanceLog;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\AttendanceCalculator;
use App\Services\AttendanceSyncService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AttendancePermissionSyncTest extends TestCase
{
    use RefreshDatabase;

    private string $date = '2026-06-22'; // Monday

    private string $serial = 'STUB-001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

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

    private function punch(string $code, string $time, ?string $date = null): void
    {
        RawAttendanceLog::create([
            'device_serial' => $this->serial,
            'device_user_id' => $code,
            'punched_at' => Carbon::parse(($date ?? $this->date)." {$time}"),
        ]);
    }

    private function approved(Employee $employee, array $overrides = []): PermissionRequest
    {
        $reviewer = User::factory()->create();
        $reviewer->assignRole(RoleName::HrOfficer->value);

        return PermissionRequest::create(array_merge([
            'employee_id' => $employee->id,
            'type' => PermissionType::Permission,
            'start_date' => $this->date,
            'end_date' => $this->date,
            'reason' => 'Bank appointment.',
            'status' => PermissionStatus::Approved,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ], $overrides));
    }

    private function sync(): AttendanceSyncService
    {
        return app(AttendanceSyncService::class);
    }

    private function calculator(): AttendanceCalculator
    {
        return app(AttendanceCalculator::class);
    }

    public function test_approved_permission_flips_a_no_punch_day_to_leave_status(): void
    {
        $employee = $this->makeEmployee();
        // The calculator first marks the no-punch day Absent.
        $this->calculator()->computeForDate(Carbon::parse($this->date));
        $this->assertSame(AttendanceStatus::Absent, AttendanceRecord::first()->status);

        $request = $this->approved($employee);
        $result = $this->sync()->syncForApproval($request);

        $record = AttendanceRecord::where('employee_id', $employee->id)->first();
        $this->assertSame(AttendanceStatus::PermissionApproved, $record->status);
        $this->assertSame($request->id, $record->permission_request_id);
        $this->assertSame(['created' => 0, 'updated' => 1, 'skipped' => 0], $result);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'attendance.synced',
            'auditable_id' => $record->id,
        ]);
    }

    public function test_sync_creates_a_record_when_none_exists(): void
    {
        $employee = $this->makeEmployee();

        $result = $this->sync()->syncForApproval($this->approved($employee, [
            'type' => PermissionType::OfficialLeave,
        ]));

        $record = AttendanceRecord::where('employee_id', $employee->id)->first();
        $this->assertSame(AttendanceStatus::OfficialLeave, $record->status);
        $this->assertSame(['created' => 1, 'updated' => 0, 'skipped' => 0], $result);
    }

    public function test_sync_is_idempotent(): void
    {
        $employee = $this->makeEmployee();
        $request = $this->approved($employee);

        $this->sync()->syncForApproval($request);
        $second = $this->sync()->syncForApproval($request);

        $this->assertSame(1, AttendanceRecord::where('employee_id', $employee->id)->count());
        $this->assertSame(['created' => 0, 'updated' => 0, 'skipped' => 1], $second);
        $this->assertSame(1, AuditLog::where('action', 'attendance.synced')->count());
    }

    public function test_sync_covers_every_day_in_a_range(): void
    {
        $employee = $this->makeEmployee();

        $result = $this->sync()->syncForApproval($this->approved($employee, [
            'type' => PermissionType::OfficialLeave,
            'start_date' => '2026-06-22',
            'end_date' => '2026-06-24',
        ]));

        $this->assertSame(3, AttendanceRecord::where('employee_id', $employee->id)->count());
        $this->assertSame(3, $result['created']);
        $this->assertTrue(
            AttendanceRecord::where('employee_id', $employee->id)
                ->get()->every(fn ($r) => $r->status === AttendanceStatus::OfficialLeave),
        );
    }

    public function test_real_punch_is_not_overwritten_by_a_leave_overlay(): void
    {
        $employee = $this->makeEmployee();
        $this->punch('EMP-0001', '08:00');
        $this->punch('EMP-0001', '17:00');
        $this->calculator()->computeForDate(Carbon::parse($this->date));
        $this->assertSame(AttendanceStatus::Present, AttendanceRecord::first()->status);

        $result = $this->sync()->syncForApproval($this->approved($employee));

        $record = AttendanceRecord::where('employee_id', $employee->id)->first();
        $this->assertSame(AttendanceStatus::Present, $record->status);
        $this->assertNull($record->permission_request_id);
        $this->assertSame(1, $result['skipped']);
    }

    public function test_manual_correction_outranks_the_sync(): void
    {
        $employee = $this->makeEmployee();
        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'work_date' => $this->date,
            'status' => AttendanceStatus::SickLeave,
            'is_manual' => true,
            'remarks' => 'HR set this by hand.',
        ]);

        $result = $this->sync()->syncForApproval($this->approved($employee));

        $record = AttendanceRecord::where('employee_id', $employee->id)->first();
        $this->assertTrue($record->is_manual);
        $this->assertNull($record->permission_request_id);
        $this->assertSame(1, $result['skipped']);
    }

    public function test_calculator_preserves_a_synced_leave_day_with_no_punch(): void
    {
        $employee = $this->makeEmployee();
        $this->sync()->syncForApproval($this->approved($employee));

        // Nightly recompute with no punches must NOT clobber the leave back to Absent.
        $this->calculator()->computeForDate(Carbon::parse($this->date));

        $record = AttendanceRecord::where('employee_id', $employee->id)->first();
        $this->assertSame(AttendanceStatus::PermissionApproved, $record->status);
    }

    public function test_calculator_clears_the_link_when_a_punch_arrives(): void
    {
        $employee = $this->makeEmployee();
        $this->sync()->syncForApproval($this->approved($employee));

        // The employee actually showed up that day after all — reality wins.
        $this->punch('EMP-0001', '08:00');
        $this->punch('EMP-0001', '17:00');
        $this->calculator()->computeForDate(Carbon::parse($this->date));

        $record = AttendanceRecord::where('employee_id', $employee->id)->first();
        $this->assertSame(AttendanceStatus::Present, $record->status);
        $this->assertNull($record->permission_request_id);
    }

    public function test_approving_via_http_syncs_attendance_end_to_end(): void
    {
        $employee = $this->makeEmployee();
        $this->calculator()->computeForDate(Carbon::parse($this->date));

        $request = PermissionRequest::create([
            'employee_id' => $employee->id,
            'type' => PermissionType::SickLeave,
            'start_date' => $this->date,
            'end_date' => $this->date,
            'reason' => 'Unwell.',
            'status' => PermissionStatus::Pending,
        ]);

        $hr = User::factory()->create();
        $hr->assignRole(RoleName::HrOfficer->value);

        $this->actingAs($hr)->put("/permission-requests/{$request->id}/review", [
            'decision' => 'approved',
            'review_note' => 'Get well soon.',
        ])->assertRedirect('/permission-requests');

        $record = AttendanceRecord::where('employee_id', $employee->id)->first();
        $this->assertSame(AttendanceStatus::SickLeave, $record->status);
        $this->assertSame($request->id, $record->permission_request_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'attendance.synced']);
    }
}
