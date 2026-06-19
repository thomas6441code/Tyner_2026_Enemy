<?php

namespace Tests\Feature;

use App\Models\BiometricDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BiometricDeviceListTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_secret(): void
    {
        $this->getJson('/api/biometric/devices')->assertForbidden();
    }

    public function test_returns_only_active_devices(): void
    {
        BiometricDevice::create([
            'name' => 'Front Door', 'type' => 'stub', 'serial' => 'STUB-001', 'status' => 'active',
        ]);
        BiometricDevice::create([
            'name' => 'Back Door', 'type' => 'stub', 'serial' => 'STUB-002', 'status' => 'inactive',
        ]);

        $response = $this->withHeaders(['X-Internal-Secret' => 'testing-secret'])
            ->getJson('/api/biometric/devices');

        $response->assertOk();
        $devices = $response->json('devices');
        $this->assertCount(1, $devices);
        $this->assertSame('STUB-001', $devices[0]['serial']);
    }

    public function test_password_round_trips_through_json(): void
    {
        BiometricDevice::create([
            'name' => 'Gate', 'type' => 'hikvision', 'serial' => 'HIK-001',
            'host' => '10.0.0.6', 'username' => 'admin', 'password' => 'plain-secret',
            'status' => 'active',
        ]);

        $response = $this->withHeaders(['X-Internal-Secret' => 'testing-secret'])
            ->getJson('/api/biometric/devices');

        $this->assertSame('plain-secret', $response->json('devices.0.password'));
    }
}
