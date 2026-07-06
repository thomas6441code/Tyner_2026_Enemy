<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\RoleName;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReportTest extends TestCase
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

    private function month(): Carbon
    {
        return Carbon::now()->startOfMonth();
    }

    /**
     * Seed one active employee with an Absent day and an approved-permission (leave) day in the
     * current month, so the report can prove it distinguishes real absence from approved leave.
     */
    private function seedEmployee(): Employee
    {
        $schedule = WorkSchedule::create([
            'name' => 'Standard', 'start_time' => '08:00', 'end_time' => '17:00', 'grace_period_minutes' => 15,
        ]);
        $employee = Employee::create([
            'employee_code' => 'EMP-0001', 'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'status' => 'active', 'work_schedule_id' => $schedule->id,
        ]);

        $month = $this->month();
        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'work_date' => $month->copy()->addDays(1)->toDateString(),
            'status' => AttendanceStatus::Present->value,
            'worked_minutes' => 480,
        ]);
        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'work_date' => $month->copy()->addDays(2)->toDateString(),
            'status' => AttendanceStatus::Absent->value,
        ]);
        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'work_date' => $month->copy()->addDays(3)->toDateString(),
            'status' => AttendanceStatus::PermissionApproved->value,
        ]);

        return $employee;
    }

    public function test_admin_can_view_report_page(): void
    {
        $this->seedEmployee();

        $this->actingAs($this->admin())->get('/reports')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('reports/attendance')
                ->has('report.rows', 1)
                ->where('report.rows.0.absent', 1)
                ->where('report.rows.0.leave', 1)
                ->where('report.rows.0.present', 1)
            );
    }

    public function test_report_counts_approved_leave_as_leave_not_absent(): void
    {
        $this->seedEmployee();

        // The row has 3 records: 1 present, 1 absent, 1 approved-permission (leave).
        // Attendance rate = (3 - 1 absent) / 3 = 66.7%, proving leave is not treated as absent.
        $this->actingAs($this->admin())
            ->get('/reports?from='.$this->month()->toDateString().'&to='.$this->month()->copy()->endOfMonth()->toDateString())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.totals.absent', 1)
                ->where('report.totals.leave', 1)
                ->where('report.totals.attendance_rate', 66.7)
            );
    }

    public function test_employee_cannot_view_reports_or_exports(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RoleName::Employee->value);

        $this->actingAs($user)->get('/reports')->assertForbidden();
        $this->actingAs($user)->get('/reports/export/csv')->assertForbidden();
        $this->actingAs($user)->get('/reports/export/pdf')->assertForbidden();
    }

    public function test_csv_export_downloads(): void
    {
        $this->seedEmployee();

        $response = $this->actingAs($this->admin())->get('/reports/export/csv');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('EMP-0001', $response->streamedContent());
    }

    public function test_pdf_export_downloads(): void
    {
        $this->seedEmployee();

        $response = $this->actingAs($this->admin())->get('/reports/export/pdf');

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));
    }
}
