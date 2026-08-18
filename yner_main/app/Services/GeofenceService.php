<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\WorkLocation;

/**
 * Server-side geofence maths for the mobile check-in channel.
 *
 * THREAT MODEL — read before changing anything here.
 *
 * A WebAuthn assertion proves *which device* is presenting the request. It says nothing about
 * *where that device is*. Coordinates arrive from `navigator.geolocation` in the browser and are
 * spoofable with a devtools sensor override or a mock-location app. Therefore:
 *
 *   - Distance is computed HERE, on the server, from the raw coordinates. Any distance the
 *     client sends is display-only and must never be trusted or persisted as authoritative.
 *   - Every attempt, accepted or rejected, is persisted with IP and user agent. The audit
 *     trail is the real control; the geofence is a speed bump.
 *   - Mobile check-in is a *supplement* to the biometric channel, not a replacement. This is a
 *     product constraint to state to stakeholders, not a bug to be fixed in code.
 *
 * Implemented in pure PHP rather than with `ST_Distance_Sphere`: dev/prod run MySQL 8 but the
 * test suite runs SQLite in-memory, which has no spatial functions. One implementation, both
 * engines, and the maths is unit-testable without a database at all.
 */
class GeofenceService
{
    /**
     * Mean Earth radius in metres (IUGG). Haversine assumes a sphere; the error against the
     * WGS-84 ellipsoid is ~0.5%, far below the GPS accuracy floor we enforce elsewhere.
     */
    public const EARTH_RADIUS_METERS = 6_371_000.0;

    /**
     * Great-circle distance between two WGS-84 coordinates, in metres.
     */
    public function distanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLon = deg2rad($lon2 - $lon1);

        $a = sin($deltaLat / 2) ** 2
            + cos($lat1Rad) * cos($lat2Rad) * sin($deltaLon / 2) ** 2;

        // atan2 form rather than asin: numerically stable for antipodal points.
        $c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));

        return self::EARTH_RADIUS_METERS * $c;
    }

    /**
     * Resolve the location an employee is geofenced against: their own, else their
     * department's, else none.
     *
     * FAIL CLOSED — there is deliberately no global fallback location. A system-wide default
     * would silently geofence every unconfigured employee against head office and produce
     * attendance that looks plausible and is wrong, which is the worst failure mode available
     * here. A null return means the caller must refuse the check-in.
     */
    public function resolveLocationFor(Employee $employee): ?WorkLocation
    {
        $location = $employee->workLocation
            ?? $employee->department?->workLocation;

        return $location?->is_active ? $location : null;
    }

    /**
     * Is the given coordinate inside the location's radius?
     *
     * Inclusive at the boundary: a fix landing exactly on `radius_meters` is accepted.
     */
    public function isWithin(WorkLocation $location, float $latitude, float $longitude): bool
    {
        return $this->distanceTo($location, $latitude, $longitude) <= (float) $location->radius_meters;
    }

    /**
     * Distance in metres from a coordinate to the centre of a work location.
     *
     * The explicit float casts matter: `latitude`/`longitude` are decimal columns, returned as
     * strings by MySQL and floats by SQLite. The model casts them, but callers may hand us a
     * freshly-built or array-hydrated instance.
     */
    public function distanceTo(WorkLocation $location, float $latitude, float $longitude): float
    {
        return $this->distanceMeters(
            (float) $location->latitude,
            (float) $location->longitude,
            $latitude,
            $longitude,
        );
    }
}
