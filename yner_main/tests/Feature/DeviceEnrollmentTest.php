<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\BiometricDevice;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function makeDevice(string $serial = 'STUB-001'): BiometricDevice
    {
        return BiometricDevice::create([
            'name' => 'Device', 'type' => 'stub', 'serial' => $serial, 'status' => 'active',
        ]);
    }

    private function makeEmployee(string $code = 'EMP-001'): Employee
    {
        return Employee::create([
            'employee_code' => $code, 'first_name' => 'Jane', 'last_name' => 'Doe', 'status' => 'active',
        ]);
    }

    public function test_admin_can_create_enrollment(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleName::Admin->value);
        $device = $this->makeDevice();
        $employee = $this->makeEmployee();

        $response = $this->actingAs($admin)->post('/device-enrollments', [
            'biometric_device_id' => $device->id,
            'employee_id' => $employee->id,
            'device_user_id' => 'U001',
        ]);

        $response->assertRedirect('/device-enrollments');
        $this->assertDatabaseHas('device_enrollments', ['device_user_id' => 'U001']);
    }

    public function test_duplicate_device_user_id_on_same_device_is_rejected(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleName::Admin->value);
        $device = $this->makeDevice();
        $employeeA = $this->makeEmployee('EMP-001');
        $employeeB = $this->makeEmployee('EMP-002');

        $this->actingAs($admin)->post('/device-enrollments', [
            'biometric_device_id' => $device->id,
            'employee_id' => $employeeA->id,
            'device_user_id' => 'U001',
        ])->assertRedirect('/device-enrollments');

        $this->actingAs($admin)->post('/device-enrollments', [
            'biometric_device_id' => $device->id,
            'employee_id' => $employeeB->id,
            'device_user_id' => 'U001',
        ])->assertSessionHasErrors('device_user_id');
    }

    public function test_same_device_user_id_on_different_device_is_allowed(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleName::Admin->value);
        $deviceA = $this->makeDevice('STUB-001');
        $deviceB = $this->makeDevice('STUB-002');
        $employeeA = $this->makeEmployee('EMP-001');
        $employeeB = $this->makeEmployee('EMP-002');

        $this->actingAs($admin)->post('/device-enrollments', [
            'biometric_device_id' => $deviceA->id,
            'employee_id' => $employeeA->id,
            'device_user_id' => 'U001',
        ])->assertRedirect('/device-enrollments');

        $this->actingAs($admin)->post('/device-enrollments', [
            'biometric_device_id' => $deviceB->id,
            'employee_id' => $employeeB->id,
            'device_user_id' => 'U001',
        ])->assertRedirect('/device-enrollments');

        $this->assertDatabaseCount('device_enrollments', 2);
    }

    public function test_hr_officer_is_forbidden(): void
    {
        $hr = User::factory()->create();
        $hr->assignRole(RoleName::HrOfficer->value);

        $this->actingAs($hr)->get('/device-enrollments')->assertForbidden();
    }
}
