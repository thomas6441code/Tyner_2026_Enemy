<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\PermissionStatus;
use App\Enums\PermissionType;
use App\Enums\RoleName;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PermissionRequest;
use App\Models\User;
use App\Models\WorkSchedule;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A day the employee was never on the clock — an absence or any approved-leave status —
 * must export with blank time columns, even when stale punch data sits on the record.
 * A "Sick Leave" row printing 08:50–16:00 reads as a worked day, which is exactly the
 * kind of mismatch the sync engine exists to prevent.
 */
class AttendanceExportStatusRenderingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleName::Admin->value);

        return $admin;
    }

    private function employee(): Employee
    {
        $schedule = WorkSchedule::create([
            'name' => 'Standard', 'start_time' => '08:00', 'end_time' => '17:00', 'grace_period_minutes' => 15,
        ]);

        return Employee::create([
            'employee_code' => 'EMP-0001',
            'first_name' => 'Neema',
            'last_name' => 'Mollel',
            'status' => 'active',
            'work_schedule_id' => $schedule->id,
        ]);
    }

    /**
     * Every leave status blanks the time columns; a genuinely worked day keeps them.
     */
    public function test_leave_rows_export_without_clock_times(): void
    {
        $employee = $this->employee();
        $date = Carbon::now()->startOfMonth();

        $statuses = [
            AttendanceStatus::Present,
            AttendanceStatus::Late,
            AttendanceStatus::Absent,
            AttendanceStatus::OfficialLeave,
            AttendanceStatus::SickLeave,
            AttendanceStatus::PermissionApproved,
            AttendanceStatus::FieldDuty,
        ];

        // Every record carries punch data, so any surviving time proves it was not suppressed.
        foreach ($statuses as $i => $status) {
            $day = $date->copy()->addDays($i);

            AttendanceRecord::create([
                'employee_id' => $employee->id,
                'work_date' => $day->toDateString(),
                'status' => $status,
                'first_in' => $day->copy()->setTime(8, 50),
                'last_out' => $day->copy()->setTime(16, 0),
                'worked_minutes' => 430,
                'late_minutes' => 38,
            ]);
        }

        $csv = $this->actingAs($this->admin())
            ->get('/attendance/export/excel?'.http_build_query([
                'from' => $date->toDateString(),
                'to' => $date->copy()->addDays(count($statuses))->toDateString(),
            ]))
            ->streamedContent();

        $lines = array_values(array_filter(explode("\n", str_replace("\r", '', $csv))));

        foreach ($statuses as $i => $status) {
            $line = $lines[$i + 1]; // +1 skips the header row

            $this->assertStringContainsString($status->label(), $line);

            if ($status->showsTimes()) {
                $this->assertStringContainsString('08:50', $line, "{$status->label()} should keep its times");
                $this->assertStringContainsString('16:00', $line);
                $this->assertStringContainsString('38', $line, "{$status->label()} should keep late minutes");
            } else {
                $this->assertStringNotContainsString('08:50', $line, "{$status->label()} must not print a check-in time");
                $this->assertStringNotContainsString('16:00', $line, "{$status->label()} must not print a check-out time");
                $this->assertStringNotContainsString('7.2', $line, "{$status->label()} must not print worked hours");
                $this->assertStringNotContainsString('38', $line, "{$status->label()} must not print late minutes");
            }
        }
    }

    /**
     * The reason column shows what the employee actually submitted, not the sync
     * engine's generated "Synced from approved ..." note.
     */
    public function test_leave_row_shows_the_submitted_permission_reason(): void
    {
        $employee = $this->employee();
        $date = Carbon::now()->startOfMonth();

        $request = PermissionRequest::create([
            'employee_id' => $employee->id,
            'type' => PermissionType::SickLeave,
            'start_date' => $date->toDateString(),
            'end_date' => $date->toDateString(),
            'reason' => 'Attending a clinic appointment at Muhimbili',
            'status' => PermissionStatus::Approved,
        ]);

        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'work_date' => $date->toDateString(),
            'status' => AttendanceStatus::SickLeave,
            'permission_request_id' => $request->id,
            'remarks' => 'Synced from approved Sick Leave (request #'.$request->id.').',
        ]);

        $csv = $this->actingAs($this->admin())
            ->get('/attendance/export/excel?'.http_build_query([
                'from' => $date->toDateString(),
                'to' => $date->toDateString(),
            ]))
            ->streamedContent();

        $this->assertStringContainsString('Attending a clinic appointment at Muhimbili', $csv);
        $this->assertStringNotContainsString('Synced from approved', $csv);
    }

    /**
     * The PDF export still renders once times are suppressed.
     */
    public function test_pdf_export_downloads_with_suppressed_times(): void
    {
        $employee = $this->employee();
        $date = Carbon::now()->startOfMonth();

        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'work_date' => $date->toDateString(),
            'status' => AttendanceStatus::SickLeave,
            'first_in' => $date->copy()->setTime(8, 50),
            'last_out' => $date->copy()->setTime(16, 0),
            'worked_minutes' => 430,
            'late_minutes' => 38,
        ]);

        $response = $this->actingAs($this->admin())
            ->get('/attendance/export/pdf?'.http_build_query([
                'from' => $date->toDateString(),
                'to' => $date->toDateString(),
            ]));

        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }
}
