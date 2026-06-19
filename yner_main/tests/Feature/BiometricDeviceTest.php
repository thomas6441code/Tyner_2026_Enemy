<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\BiometricDevice;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BiometricDeviceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_view_index(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleName::Admin->value);

        $this->actingAs($admin)->get('/biometric-devices')->assertOk();
    }

    public function test_admin_can_create_device(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleName::Admin->value);

        $response = $this->actingAs($admin)->post('/biometric-devices', [
            'name' => 'Front Door',
            'type' => 'stub',
            'serial' => 'STUB-001',
            'status' => 'active',
        ]);

        $response->assertRedirect('/biometric-devices');
        $this->assertDatabaseHas('biometric_devices', ['serial' => 'STUB-001']);
    }

    public function test_admin_can_delete_device(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleName::Admin->value);
        $device = BiometricDevice::create([
            'name' => 'Front Door', 'type' => 'stub', 'serial' => 'STUB-001', 'status' => 'active',
        ]);

        $this->actingAs($admin)->delete("/biometric-devices/{$device->id}")->assertRedirect('/biometric-devices');
        $this->assertDatabaseMissing('biometric_devices', ['id' => $device->id]);
    }

    public function test_hr_officer_is_forbidden_from_index_and_create(): void
    {
        $hr = User::factory()->create();
        $hr->assignRole(RoleName::HrOfficer->value);

        $this->actingAs($hr)->get('/biometric-devices')->assertForbidden();
        $this->actingAs($hr)->get('/biometric-devices/create')->assertForbidden();
        $this->actingAs($hr)->post('/biometric-devices', ['name' => 'X'])->assertForbidden();
    }

    public function test_employee_is_forbidden(): void
    {
        $employee = User::factory()->create();
        $employee->assignRole(RoleName::Employee->value);

        $this->actingAs($employee)->get('/biometric-devices')->assertForbidden();
    }

    public function test_password_is_encrypted_at_rest(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleName::Admin->value);

        $this->actingAs($admin)->post('/biometric-devices', [
            'name' => 'Gate',
            'type' => 'hikvision',
            'serial' => 'HIK-001',
            'host' => '10.0.0.6',
            'username' => 'admin',
            'password' => 'plain-secret',
            'status' => 'active',
        ]);

        $rawPassword = DB::table('biometric_devices')->where('serial', 'HIK-001')->value('password');

        $this->assertNotSame('plain-secret', $rawPassword);
        $this->assertSame('plain-secret', BiometricDevice::where('serial', 'HIK-001')->first()->password);
    }
}
