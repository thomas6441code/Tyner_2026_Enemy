<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\RoleName;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use App\Services\AttendanceCalculator;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AttendanceCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function userWithRole(RoleName $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    private function record(?int $userId = null): AttendanceRecord
    {
        $employee = Employee::create([
            'employee_code' => 'EMP-0001', 'first_name' => 'Test', 'last_name' => 'Person',
            'status' => 'active', 'user_id' => $userId,
        ]);

        return AttendanceRecord::create([
            'employee_id' => $employee->id,
            'work_date' => '2026-06-22',
            'status' => AttendanceStatus::Absent,
        ]);
    }

    public function test_hr_can_correct_a_record_and_it_is_audited(): void
    {
        $hr = $this->userWithRole(RoleName::HrOfficer);
        $record = $this->record();

        $response = $this->actingAs($hr)->put("/attendance/{$record->id}", [
            'status' => AttendanceStatus::SickLeave->value,
            'first_in' => '',
            'last_out' => '',
            'remarks' => 'Approved sick leave, certificate filed.',
        ]);

        $response->assertRedirect('/attendance');

        $record->refresh();
        $this->assertSame(AttendanceStatus::SickLeave, $record->status);
        $this->assertTrue($record->is_manual);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $hr->id,
            'auditable_type' => AttendanceRecord::class,
            'auditable_id' => $record->id,
            'action' => 'attendance.corrected',
        ]);
    }

    public function test_correction_recomputes_worked_minutes_from_times(): void
    {
        $admin = $this->userWithRole(RoleName::Admin);
        $record = $this->record();

        $this->actingAs($admin)->put("/attendance/{$record->id}", [
            'status' => AttendanceStatus::Present->value,
            'first_in' => '08:00',
            'last_out' => '17:00',
            'remarks' => 'Device missed punches; verified manually.',
        ])->assertRedirect('/attendance');

        $this->assertSame(540, $record->refresh()->worked_minutes);
    }

    public function test_employee_cannot_correct(): void
    {
        $employee = $this->userWithRole(RoleName::Employee);
        $record = $this->record($employee->id);

        $this->actingAs($employee)->put("/attendance/{$record->id}", [
            'status' => AttendanceStatus::Present->value,
            'remarks' => 'Trying to fix my own record.',
        ])->assertForbidden();
    }

    public function test_remarks_are_required(): void
    {
        $hr = $this->userWithRole(RoleName::HrOfficer);
        $record = $this->record();

        $this->actingAs($hr)->put("/attendance/{$record->id}", [
            'status' => AttendanceStatus::Present->value,
        ])->assertSessionHasErrors('remarks');
    }

    public function test_recompute_leaves_a_correction_intact(): void
    {
        $hr = $this->userWithRole(RoleName::HrOfficer);
        $record = $this->record();

        $this->actingAs($hr)->put("/attendance/{$record->id}", [
            'status' => AttendanceStatus::FieldDuty->value,
            'remarks' => 'Off-site assignment.',
        ])->assertRedirect('/attendance');

        $this->assertSame(AttendanceStatus::FieldDuty, $record->refresh()->status, 'PUT did not persist');
        $this->assertTrue($record->is_manual, 'is_manual not set by PUT');

        app(AttendanceCalculator::class)->computeForDate(Carbon::parse('2026-06-22'));

        $this->assertSame(AttendanceStatus::FieldDuty, $record->refresh()->status);
    }
}
