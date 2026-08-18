<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            // Before OrgSeeder: it assigns these sites to departments and employees.
            WorkLocationSeeder::class,
            OrgSeeder::class,
            AttendanceSeeder::class,
            PermissionSeeder::class,
            AiInsightSeeder::class,
        ]);
    }
}
