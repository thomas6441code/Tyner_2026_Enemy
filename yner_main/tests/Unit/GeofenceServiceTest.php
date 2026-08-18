<?php

namespace Tests\Unit;

use App\Models\Department;
use App\Models\Employee;
use App\Models\WorkLocation;
use App\Services\GeofenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The geofence maths is the whole enforcement mechanism for the mobile channel, so it is
 * pinned against known-answer fixtures rather than against itself.
 */
class GeofenceServiceTest extends TestCase
{
    use RefreshDatabase;

    private GeofenceService $geofence;

    protected function setUp(): void
    {
        parent::setUp();

        $this->geofence = new GeofenceService;
    }

    public function test_identical_points_are_zero_metres_apart(): void
    {
        $this->assertSame(0.0, $this->geofence->distanceMeters(-6.7924, 39.2083, -6.7924, 39.2083));
    }

    public function test_one_degree_of_longitude_at_the_equator_is_about_111_km(): void
    {
        $distance = $this->geofence->distanceMeters(0.0, 0.0, 0.0, 1.0);

        // 111.195km on a sphere of radius 6 371 000m. Tolerance covers nothing but float noise.
        $this->assertEqualsWithDelta(111_194.9, $distance, 1.0);
    }

    public function test_one_degree_of_latitude_is_about_111_km_at_any_longitude(): void
    {
        $distance = $this->geofence->distanceMeters(-6.0, 39.0, -5.0, 39.0);

        $this->assertEqualsWithDelta(111_194.9, $distance, 1.0);
    }

    public function test_longitude_degrees_shrink_towards_the_poles(): void
    {
        $atEquator = $this->geofence->distanceMeters(0.0, 0.0, 0.0, 1.0);
        $atSixtyDegrees = $this->geofence->distanceMeters(60.0, 0.0, 60.0, 1.0);

        // cos(60°) = 0.5, so a degree of longitude is half as wide there.
        $this->assertEqualsWithDelta($atEquator / 2, $atSixtyDegrees, 1.0);
    }

    public function test_distance_is_symmetric(): void
    {
        $there = $this->geofence->distanceMeters(-6.7924, 39.2083, -6.8000, 39.2200);
        $back = $this->geofence->distanceMeters(-6.8000, 39.2200, -6.7924, 39.2083);

        $this->assertEqualsWithDelta($there, $back, 0.000_001);
    }

    public function test_a_point_at_exactly_the_radius_is_inside_the_fence(): void
    {
        $location = $this->location(['latitude' => 0.0, 'longitude' => 0.0, 'radius_meters' => 150]);

        // Walk east until we are as close to 150m as a 1e-7 degree grid allows, then assert
        // the boundary is inclusive on the inside and exclusive just beyond it.
        $metresPerDegree = $this->geofence->distanceMeters(0.0, 0.0, 0.0, 1.0);
        $justInside = (150.0 - 0.01) / $metresPerDegree;
        $justOutside = (150.0 + 0.01) / $metresPerDegree;

        $this->assertTrue($this->geofence->isWithin($location, 0.0, $justInside));
        $this->assertFalse($this->geofence->isWithin($location, 0.0, $justOutside));
    }

    public function test_is_within_rejects_a_point_beyond_the_radius(): void
    {
        $location = $this->location(['latitude' => -6.7924, 'longitude' => 39.2083, 'radius_meters' => 150]);

        // ~1.1km north.
        $this->assertFalse($this->geofence->isWithin($location, -6.7824, 39.2083));
    }

    public function test_distance_to_uses_the_location_centre(): void
    {
        $location = $this->location(['latitude' => 0.0, 'longitude' => 0.0]);

        $this->assertEqualsWithDelta(111_194.9, $this->geofence->distanceTo($location, 0.0, 1.0), 1.0);
    }

    public function test_it_resolves_the_employees_own_location_first(): void
    {
        $own = $this->location(['name' => 'Field Post']);
        $departmental = $this->location(['name' => 'Head Office']);

        $employee = $this->employee($departmental, $own);

        $this->assertTrue($own->is($this->geofence->resolveLocationFor($employee)));
    }

    public function test_it_falls_back_to_the_department_location(): void
    {
        $departmental = $this->location(['name' => 'Head Office']);

        $employee = $this->employee($departmental, null);

        $this->assertTrue($departmental->is($this->geofence->resolveLocationFor($employee)));
    }

    public function test_it_resolves_to_null_when_neither_is_set(): void
    {
        // Fail closed. There is no system-wide default on purpose: geofencing an unconfigured
        // employee against head office produces attendance that looks right and is not.
        $this->assertNull($this->geofence->resolveLocationFor($this->employee(null, null)));
    }

    public function test_an_inactive_location_does_not_resolve(): void
    {
        $inactive = $this->location(['name' => 'Closed Site', 'is_active' => false]);

        $this->assertNull($this->geofence->resolveLocationFor($this->employee(null, $inactive)));
        $this->assertNull($this->geofence->resolveLocationFor($this->employee($inactive, null)));
    }

    private function location(array $attributes = []): WorkLocation
    {
        return WorkLocation::create($attributes + [
            'name' => 'Site '.fake()->unique()->numberBetween(1, 100_000),
            'latitude' => -6.7924,
            'longitude' => 39.2083,
            'radius_meters' => 150,
            'is_active' => true,
        ]);
    }

    private function employee(?WorkLocation $departmentLocation, ?WorkLocation $ownLocation): Employee
    {
        $department = Department::create([
            'name' => 'Dept '.fake()->unique()->numberBetween(1, 100_000),
            'work_location_id' => $departmentLocation?->id,
        ]);

        return Employee::create([
            'department_id' => $department->id,
            'work_location_id' => $ownLocation?->id,
            'employee_code' => 'E'.fake()->unique()->numberBetween(1, 100_000),
            'first_name' => 'Test',
            'last_name' => 'Employee',
            'status' => 'active',
        ]);
    }
}
