<?php

namespace Database\Seeders;

use App\Models\WorkLocation;
use Illuminate\Database\Seeder;

/**
 * Geofence sites for the mobile check-in channel.
 *
 * Coordinates are real IFM/Tanzania locations so a demo on a map looks sensible, but they are
 * only useful for a live phone test if you are physically there — for local testing, edit a
 * location to your own coordinates from the Work Locations screen rather than editing this
 * seeder, so a re-seed does not overwrite your fix.
 *
 * Radii start at 150m: GPS on a phone is routinely 20–50m out, and a tighter fence rejects
 * people who are genuinely standing in the building.
 */
class WorkLocationSeeder extends Seeder
{
    public function run(): void
    {
        $locations = [
            [
                'name' => 'IFM Main Campus',
                'address' => 'Shaaban Robert Street, Dar es Salaam',
                'latitude' => -6.8123000,
                'longitude' => 39.2891000,
                'radius_meters' => 150,
            ],
            [
                'name' => 'IFM Mwanza Centre',
                'address' => 'Nyerere Road, Mwanza',
                'latitude' => -2.5164000,
                'longitude' => 32.9175000,
                'radius_meters' => 200,
            ],
            [
                'name' => 'Dodoma Liaison Office',
                'address' => 'Government City, Dodoma',
                'latitude' => -6.1630000,
                'longitude' => 35.7516000,
                'radius_meters' => 120,
            ],
        ];

        foreach ($locations as $location) {
            WorkLocation::firstOrCreate(
                ['name' => $location['name']],
                $location + ['is_active' => true],
            );
        }
    }
}
