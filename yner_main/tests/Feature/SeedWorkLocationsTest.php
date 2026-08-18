<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\WorkLocation;
use App\Services\GeofenceService;
use Database\Seeders\OrgSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\WorkLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The seeded org must be able to use the mobile channel out of the box — otherwise every
 * demo starts with a `no_work_location` rejection and nobody can tell a config gap from a bug.
 */
class SeedWorkLocationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, WorkLocationSeeder::class, OrgSeeder::class]);
    }

    public function test_it_seeds_active_geofence_sites(): void
    {
        $this->assertGreaterThan(0, WorkLocation::where('is_active', true)->count());
    }

    public function test_every_seeded_employee_resolves_to_a_location(): void
    {
        $geofence = app(GeofenceService::class);

        foreach (Employee::with(['department', 'workLocation'])->get() as $employee) {
            $this->assertNotNull(
                $geofence->resolveLocationFor($employee),
                "Seeded employee {$employee->employee_code} has no resolvable work location.",
            );
        }
    }

    public function test_an_employee_level_location_overrides_the_department(): void
    {
        $employee = Employee::where('employee_code', 'EMP-0004')->firstOrFail();

        $resolved = app(GeofenceService::class)->resolveLocationFor($employee);

        $this->assertSame('Dodoma Liaison Office', $resolved?->name);
        $this->assertNotSame($employee->department->work_location_id, $employee->work_location_id);
    }

    public function test_reseeding_is_idempotent(): void
    {
        $before = WorkLocation::count();

        $this->seed([WorkLocationSeeder::class, OrgSeeder::class]);

        $this->assertSame($before, WorkLocation::count());
    }
}
