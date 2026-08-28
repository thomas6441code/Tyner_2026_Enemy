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
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The KPI cards on the permission and attendance indexes must describe the same slice of
 * data the list below them shows: an Employee's own records, everyone's for Admin/HR.
 * Attendance additionally counts the applied date range, not just today.
 */
class ScopedIndexStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function employeeUser(string $code): array
    {
        $user = User::factory()->create();
        $user->assignRole(RoleName::Employee->value);

        $employee = Employee::create([
            'employee_code' => $code, 'first_name' => 'Test', 'last_name' => $code,
            'status' => 'active', 'user_id' => $user->id,
        ]);

        return [$user, $employee];
    }

    private function permission(Employee $employee, PermissionStatus $status): void
    {
        PermissionRequest::create([
            'employee_id' => $employee->id,
            'type' => PermissionType::SickLeave,
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-02',
            'reason' => 'Unwell.',
            'status' => $status,
        ]);
    }

    public function test_permission_stats_count_only_the_signed_in_employees_requests(): void
    {
        [$mine, $meEmployee] = $this->employeeUser('EMP-0001');
        [, $otherEmployee] = $this->employeeUser('EMP-0002');

        $this->permission($meEmployee, PermissionStatus::Pending);
        $this->permission($meEmployee, PermissionStatus::Approved);
        $this->permission($otherEmployee, PermissionStatus::Pending);
        $this->permission($otherEmployee, PermissionStatus::Rejected);

        $this->actingAs($mine)->get('/permission-requests')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('statsScope', 'mine')
                ->where('stats.pending', 1)
                ->where('stats.approved', 1)
                ->where('stats.rejected', 0)
                ->where('stats.total', 2));
    }

    public function test_permission_stats_cover_everyone_for_hr(): void
    {
        [, $a] = $this->employeeUser('EMP-0001');
        [, $b] = $this->employeeUser('EMP-0002');

        $this->permission($a, PermissionStatus::Pending);
        $this->permission($b, PermissionStatus::Pending);
        $this->permission($b, PermissionStatus::Rejected);

        $hr = User::factory()->create();
        $hr->assignRole(RoleName::HrOfficer->value);

        $this->actingAs($hr)->get('/permission-requests')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('statsScope', 'all')
                ->where('stats.pending', 2)
                ->where('stats.rejected', 1)
                ->where('stats.total', 3));
    }

    public function test_attendance_stats_follow_the_applied_date_range(): void
    {
        [, $employee] = $this->employeeUser('EMP-0001');

        foreach ([
            ['2026-06-22', AttendanceStatus::Present],
            ['2026-06-23', AttendanceStatus::Late],
            ['2026-06-24', AttendanceStatus::Absent],
            // Outside the range asked for below — must not be counted.
            ['2026-06-29', AttendanceStatus::Present],
        ] as [$date, $status]) {
            AttendanceRecord::create([
                'employee_id' => $employee->id,
                'work_date' => $date,
                'status' => $status,
            ]);
        }

        $hr = User::factory()->create();
        $hr->assignRole(RoleName::HrOfficer->value);

        $this->actingAs($hr)->get('/attendance?from=2026-06-22&to=2026-06-24')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('statsScope', 'all')
                ->where('stats.days', 3)
                ->where('stats.employees', 1)
                ->where('stats.expected', 3)
                ->where('stats.present', 2)
                ->where('stats.late', 1)
                ->where('stats.onTime', 1)
                ->where('stats.absent', 1)
                ->where('stats.presentRemaining', 1));
    }

    public function test_attendance_stats_count_only_the_signed_in_employees_days(): void
    {
        [$mine, $meEmployee] = $this->employeeUser('EMP-0001');
        [, $otherEmployee] = $this->employeeUser('EMP-0002');

        AttendanceRecord::create([
            'employee_id' => $meEmployee->id, 'work_date' => '2026-06-22',
            'status' => AttendanceStatus::Present,
        ]);
        AttendanceRecord::create([
            'employee_id' => $otherEmployee->id, 'work_date' => '2026-06-22',
            'status' => AttendanceStatus::Absent,
        ]);

        $this->actingAs($mine)->get('/attendance?from=2026-06-22&to=2026-06-22')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('statsScope', 'mine')
                ->where('stats.employees', 1)
                ->where('stats.expected', 1)
                ->where('stats.present', 1)
                ->where('stats.absent', 0)
                ->where('stats.presentRemaining', 0));
    }
}
