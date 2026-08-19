<?php

namespace Tests\Feature;

use App\Enums\CheckInDirection;
use App\Enums\CheckInResult;
use App\Enums\DeviceStatus;
use App\Models\Employee;
use App\Models\MobileCheckIn;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EapmsStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*/health' => Http::response(['status' => 'ok'])]);
    }

    public function test_it_reports_an_empty_mobile_channel(): void
    {
        $this->artisan('eapms:status')
            ->expectsOutputToContain('Mobile check-in channel')
            ->expectsOutputToContain('No devices registered yet')
            ->expectsOutputToContain('No active work locations')
            ->assertSuccessful();
    }

    public function test_it_counts_devices_locations_and_todays_check_ins(): void
    {
        $location = WorkLocation::create([
            'name' => 'Campus', 'latitude' => -6.81, 'longitude' => 39.28,
            'radius_meters' => 150, 'is_active' => true,
        ]);

        $user = User::factory()->create();
        $employee = Employee::create([
            'employee_code' => 'EMP-0001', 'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'status' => 'active', 'user_id' => $user->id, 'work_location_id' => $location->id,
        ]);

        UserDevice::create([
            'user_id' => $user->id,
            'credential_id' => 'cred-active',
            'public_key' => '{}',
            'sign_count' => 0,
            'rp_id' => 'localhost',
            'device_name' => 'Pixel',
            'status' => DeviceStatus::Active,
        ]);

        UserDevice::create([
            'user_id' => $user->id,
            'credential_id' => 'cred-revoked',
            'public_key' => '{}',
            'sign_count' => 0,
            'rp_id' => 'localhost',
            'device_name' => 'Old phone',
            'status' => DeviceStatus::Revoked,
        ]);

        MobileCheckIn::create([
            'employee_id' => $employee->id, 'user_id' => $user->id, 'work_location_id' => $location->id,
            'work_date' => now()->toDateString(), 'direction' => CheckInDirection::In,
            'punched_at' => now(), 'latitude' => -6.81, 'longitude' => 39.28,
            'accuracy_meters' => 10, 'distance_meters' => 5, 'within_geofence' => true,
            'webauthn_verified' => true, 'result' => CheckInResult::Accepted, 'flagged' => true,
        ]);

        // Yesterday's row must not be counted in "today".
        MobileCheckIn::create([
            'employee_id' => $employee->id, 'user_id' => $user->id, 'work_location_id' => $location->id,
            'work_date' => now()->subDay()->toDateString(), 'direction' => CheckInDirection::In,
            'punched_at' => now()->subDay(), 'latitude' => -6.81, 'longitude' => 39.28,
            'accuracy_meters' => 10, 'distance_meters' => 5, 'within_geofence' => true,
            'webauthn_verified' => true, 'result' => CheckInResult::Accepted, 'flagged' => false,
        ]);

        $this->artisan('eapms:status')
            ->expectsOutputToContain('Mobile check-in channel')
            ->doesntExpectOutputToContain('No devices registered yet')
            ->doesntExpectOutputToContain('No active work locations')
            ->assertSuccessful();
    }
}
