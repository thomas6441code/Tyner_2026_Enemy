<?php

namespace Tests\Feature;

use App\Enums\PermissionStatus;
use App\Enums\PermissionType;
use App\Enums\RoleName;
use App\Models\Employee;
use App\Models\PermissionRequest;
use App\Models\User;
use App\Models\WorkSchedule;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationSystemTest extends TestCase
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

    private function employeeUser(string $code = 'EMP-0001'): array
    {
        $user = $this->userWithRole(RoleName::Employee);
        $employee = Employee::create([
            'employee_code' => $code,
            'first_name' => 'Test',
            'last_name' => 'Person',
            'status' => 'active',
            'user_id' => $user->id,
        ]);

        return [$user, $employee];
    }

    public function test_permission_submission_notifies_management_and_populates_inbox(): void
    {
        [$employeeUser, $employee] = $this->employeeUser();
        $admin = $this->userWithRole(RoleName::Admin);
        $this->userWithRole(RoleName::HrOfficer);

        $this->actingAs($employeeUser)->post('/permission-requests', [
            'type' => PermissionType::SickLeave->value,
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-02',
            'reason' => 'Flu.',
        ])->assertRedirect('/permission-requests');

        $this->assertDatabaseCount('notifications', 2);

        $this->actingAs($admin)->get('/notifications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('notifications/index')
                ->where('notifications.total', 1));

        $request = PermissionRequest::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame(PermissionStatus::Pending, $request->status);
    }

    public function test_sign_in_reminder_command_notifies_employees_without_attendance(): void
    {
        $schedule = WorkSchedule::create([
            'name' => 'Standard',
            'start_time' => '08:00',
            'end_time' => '17:00',
            'grace_period_minutes' => 15,
        ]);

        $employeeUser = $this->userWithRole(RoleName::Employee);
        Employee::create([
            'employee_code' => 'EMP-1001',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'status' => 'active',
            'work_schedule_id' => $schedule->id,
            'user_id' => $employeeUser->id,
        ]);

        $this->artisan('attendance:remind-sign-in')->assertSuccessful();

        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(1, $employeeUser->fresh()->unreadNotifications()->count());
    }
}