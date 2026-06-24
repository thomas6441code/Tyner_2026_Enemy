<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepartmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_view_department_index(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleName::Admin->value);

        $this->actingAs($admin)->get('/departments')->assertOk();
    }

    public function test_admin_can_create_department(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleName::Admin->value);

        $response = $this->actingAs($admin)->post('/departments', [
            'name' => 'Finance',
            'description' => 'Handles budgets and payroll.',
        ]);

        $response->assertRedirect('/departments');
        $this->assertDatabaseHas('departments', ['name' => 'Finance']);
    }

    public function test_hr_officer_can_view_but_not_create_department(): void
    {
        $hr = User::factory()->create();
        $hr->assignRole(RoleName::HrOfficer->value);

        $this->actingAs($hr)->get('/departments')->assertOk();
        $this->actingAs($hr)->post('/departments', ['name' => 'Finance'])->assertForbidden();
    }

    public function test_employee_cannot_view_department_index(): void
    {
        $employee = User::factory()->create();
        $employee->assignRole(RoleName::Employee->value);

        $this->actingAs($employee)->get('/departments')->assertForbidden();
    }

    public function test_admin_can_delete_department(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleName::Admin->value);
        $department = Department::create(['name' => 'ICT']);

        $this->actingAs($admin)->delete("/departments/{$department->id}")->assertRedirect('/departments');
        $this->assertDatabaseMissing('departments', ['id' => $department->id]);
    }
}
