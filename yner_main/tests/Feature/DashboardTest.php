<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_sees_admin_dashboard(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleName::Admin->value);

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('dashboard/admin'));
    }

    public function test_hr_officer_sees_hr_dashboard(): void
    {
        $hr = User::factory()->create();
        $hr->assignRole(RoleName::HrOfficer->value);

        $this->actingAs($hr)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('dashboard/hr'));
    }

    public function test_employee_sees_employee_dashboard_with_own_record(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RoleName::Employee->value);
        Employee::create([
            'user_id' => $user->id,
            'employee_code' => 'EMP-0001',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'status' => 'active',
        ]);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard/employee')
                ->where('employee.employee_code', 'EMP-0001')
            );
    }
}
