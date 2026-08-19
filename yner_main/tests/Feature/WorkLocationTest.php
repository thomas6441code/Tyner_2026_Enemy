<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkLocation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkLocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Head Office',
            'address' => 'Kivukoni, Dar es Salaam',
            'latitude' => -6.7924,
            'longitude' => 39.2083,
            'radius_meters' => 150,
            'is_active' => true,
        ];
    }

    private function location(array $overrides = []): WorkLocation
    {
        return WorkLocation::create($this->payload($overrides));
    }

    public function test_admin_can_view_work_location_index(): void
    {
        $this->actingAs($this->userWithRole(RoleName::Admin->value))
            ->get('/work-locations')
            ->assertOk();
    }

    public function test_hr_officer_can_view_work_location_index(): void
    {
        $this->actingAs($this->userWithRole(RoleName::HrOfficer->value))
            ->get('/work-locations')
            ->assertOk();
    }

    public function test_employee_cannot_view_work_location_index(): void
    {
        $this->actingAs($this->userWithRole(RoleName::Employee->value))
            ->get('/work-locations')
            ->assertForbidden();
    }

    public function test_admin_can_create_a_work_location(): void
    {
        $this->actingAs($this->userWithRole(RoleName::Admin->value))
            ->post('/work-locations', $this->payload())
            ->assertRedirect('/work-locations');

        $this->assertDatabaseHas('work_locations', [
            'name' => 'Head Office',
            'radius_meters' => 150,
        ]);
    }

    public function test_hr_officer_can_create_a_work_location(): void
    {
        // HR onboards staff to new sites, so they may add one; only Admin may delete.
        $this->actingAs($this->userWithRole(RoleName::HrOfficer->value))
            ->post('/work-locations', $this->payload(['name' => 'Field Post']))
            ->assertRedirect('/work-locations');

        $this->assertDatabaseHas('work_locations', ['name' => 'Field Post']);
    }

    public function test_employee_cannot_create_a_work_location(): void
    {
        $this->actingAs($this->userWithRole(RoleName::Employee->value))
            ->post('/work-locations', $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('work_locations', 0);
    }

    public function test_admin_can_update_a_work_location(): void
    {
        $location = $this->location();

        $this->actingAs($this->userWithRole(RoleName::Admin->value))
            ->put("/work-locations/{$location->id}", $this->payload([
                'name' => 'Head Office (Annex)',
                'radius_meters' => 300,
            ]))
            ->assertRedirect('/work-locations');

        $this->assertDatabaseHas('work_locations', [
            'id' => $location->id,
            'name' => 'Head Office (Annex)',
            'radius_meters' => 300,
        ]);
    }

    public function test_admin_can_delete_a_work_location(): void
    {
        $location = $this->location();

        $this->actingAs($this->userWithRole(RoleName::Admin->value))
            ->delete("/work-locations/{$location->id}")
            ->assertRedirect('/work-locations');

        $this->assertDatabaseMissing('work_locations', ['id' => $location->id]);
    }

    public function test_hr_officer_cannot_delete_a_work_location(): void
    {
        $location = $this->location();

        $this->actingAs($this->userWithRole(RoleName::HrOfficer->value))
            ->delete("/work-locations/{$location->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('work_locations', ['id' => $location->id]);
    }

    public function test_deleting_a_location_nulls_it_out_rather_than_orphaning_rows(): void
    {
        $location = $this->location();
        $department = Department::create(['name' => 'ICT', 'work_location_id' => $location->id]);
        $employee = Employee::create([
            'department_id' => $department->id,
            'work_location_id' => $location->id,
            'employee_code' => 'E001',
            'first_name' => 'Amina',
            'last_name' => 'Juma',
            'status' => 'active',
        ]);

        $this->actingAs($this->userWithRole(RoleName::Admin->value))
            ->delete("/work-locations/{$location->id}");

        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'work_location_id' => null]);
        $this->assertDatabaseHas('departments', ['id' => $department->id, 'work_location_id' => null]);
    }

    public function test_coordinates_are_validated_against_the_globe(): void
    {
        $this->actingAs($this->userWithRole(RoleName::Admin->value))
            ->post('/work-locations', $this->payload(['latitude' => 91, 'longitude' => 181]))
            ->assertSessionHasErrors(['latitude', 'longitude']);
    }

    public function test_a_radius_below_the_gps_accuracy_floor_is_rejected(): void
    {
        // Anything under 20m is unenforceable with consumer GPS and would reject employees
        // who are genuinely standing at the door.
        $this->actingAs($this->userWithRole(RoleName::Admin->value))
            ->post('/work-locations', $this->payload(['radius_meters' => 5]))
            ->assertSessionHasErrors('radius_meters');
    }

    public function test_an_absurdly_large_radius_is_rejected(): void
    {
        $this->actingAs($this->userWithRole(RoleName::Admin->value))
            ->post('/work-locations', $this->payload(['radius_meters' => 50_000]))
            ->assertSessionHasErrors('radius_meters');
    }

    public function test_names_are_unique(): void
    {
        $this->location();

        $this->actingAs($this->userWithRole(RoleName::Admin->value))
            ->post('/work-locations', $this->payload())
            ->assertSessionHasErrors('name');
    }

    public function test_a_location_can_keep_its_own_name_when_updated(): void
    {
        $location = $this->location();

        $this->actingAs($this->userWithRole(RoleName::Admin->value))
            ->put("/work-locations/{$location->id}", $this->payload(['radius_meters' => 200]))
            ->assertSessionHasNoErrors();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/work-locations')->assertRedirect('/login');
    }

    public function test_an_employee_can_be_assigned_a_work_location(): void
    {
        $location = $this->location();

        $this->actingAs($this->userWithRole(RoleName::Admin->value))
            ->post('/employees', [
                'first_name' => 'Amina',
                'last_name' => 'Juma',
                'status' => 'active',
                'work_location_id' => $location->id,
            ])
            ->assertRedirect('/employees');

        // The code is server-allocated; this test is about the location assignment, so it
        // matches on the name it did supply.
        $this->assertDatabaseHas('employees', [
            'first_name' => 'Amina',
            'work_location_id' => $location->id,
        ]);
    }

    public function test_a_department_can_be_assigned_a_work_location(): void
    {
        $location = $this->location();

        $this->actingAs($this->userWithRole(RoleName::Admin->value))
            ->post('/departments', [
                'name' => 'ICT',
                'work_location_id' => $location->id,
            ])
            ->assertRedirect('/departments');

        $this->assertDatabaseHas('departments', [
            'name' => 'ICT',
            'work_location_id' => $location->id,
        ]);
    }
}
