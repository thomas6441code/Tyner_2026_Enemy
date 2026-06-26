<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_create_employee(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleName::Admin->value);

        $response = $this->actingAs($admin)->post('/employees', [
            'employee_code' => 'EMP-0001',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'status' => 'active',
        ]);

        $response->assertRedirect('/employees');
        $this->assertDatabaseHas('employees', ['employee_code' => 'EMP-0001']);
    }

    public function test_hr_officer_can_list_employees(): void
    {
        $hr = User::factory()->create();
        $hr->assignRole(RoleName::HrOfficer->value);

        $this->actingAs($hr)->get('/employees')->assertOk();
    }

    public function test_employee_cannot_list_employees(): void
    {
        $employee = User::factory()->create();
        $employee->assignRole(RoleName::Employee->value);

        $this->actingAs($employee)->get('/employees')->assertForbidden();
    }

    public function test_employee_can_view_own_record(): void
    {
        // The Inertia migration dropped the GET /employees/{id} show route; the "view own
        // record" guarantee now lives in EmployeePolicy::view, asserted directly here.
        $user = User::factory()->create();
        $user->assignRole(RoleName::Employee->value);
        $employee = Employee::create([
            'user_id' => $user->id,
            'employee_code' => 'EMP-0002',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'status' => 'active',
        ]);

        $this->assertTrue($user->can('view', $employee));
    }

    public function test_employee_cannot_view_someone_elses_record(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RoleName::Employee->value);

        $otherEmployee = Employee::create([
            'employee_code' => 'EMP-0003',
            'first_name' => 'John',
            'last_name' => 'Smith',
            'status' => 'active',
        ]);

        $this->assertFalse($user->can('view', $otherEmployee));
    }
}
