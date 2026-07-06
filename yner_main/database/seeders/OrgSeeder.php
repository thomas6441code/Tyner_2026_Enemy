<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class OrgSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $departments = collect(['Finance', 'Human Resources', 'ICT', 'Registry', 'Academics'])
            ->mapWithKeys(fn (string $name) => [$name => Department::firstOrCreate(['name' => $name])]);

        $workSchedule = WorkSchedule::firstOrCreate(
            ['name' => 'Standard Office Hours'],
            ['start_time' => '08:00', 'end_time' => '17:00', 'grace_period_minutes' => 15]
        );

        $admin = User::firstOrCreate(
            ['email' => 'admin@eapms.test'],
            ['name' => 'System Admin', 'password' => Hash::make('password'), 'email_verified_at' => now()]
        );
        $admin->syncRoles([RoleName::Admin->value]);

        $hr = User::firstOrCreate(
            ['email' => 'hr@eapms.test'],
            ['name' => 'HR Officer', 'password' => Hash::make('password'), 'email_verified_at' => now()]
        );
        $hr->syncRoles([RoleName::HrOfficer->value]);

        $employeeUser = User::firstOrCreate(
            ['email' => 'employee@eapms.test'],
            ['name' => 'Jane Mwakasege', 'password' => Hash::make('password'), 'email_verified_at' => now()]
        );
        $employeeUser->syncRoles([RoleName::Employee->value]);

        // A 24-person org spread across the five departments. EMP-0001 is linked to the
        // seeded Employee login; the rest are HR records without a user account, which is the
        // realistic default (most staff never log in). Names are fixed so re-seeding is stable.
        $sampleEmployees = [
            ['code' => 'EMP-0001', 'first' => 'Jane', 'last' => 'Mwakasege', 'dept' => 'Finance', 'user' => $employeeUser],
            ['code' => 'EMP-0002', 'first' => 'John', 'last' => 'Kibwana', 'dept' => 'ICT', 'user' => null],
            ['code' => 'EMP-0003', 'first' => 'Amina', 'last' => 'Said', 'dept' => 'Human Resources', 'user' => null],
            ['code' => 'EMP-0004', 'first' => 'Peter', 'last' => 'Mushi', 'dept' => 'Registry', 'user' => null],
            ['code' => 'EMP-0005', 'first' => 'Grace', 'last' => 'Lyimo', 'dept' => 'Academics', 'user' => null],
            ['code' => 'EMP-0006', 'first' => 'Hamisi', 'last' => 'Juma', 'dept' => 'Finance', 'user' => null],
            ['code' => 'EMP-0007', 'first' => 'Neema', 'last' => 'Mollel', 'dept' => 'ICT', 'user' => null],
            ['code' => 'EMP-0008', 'first' => 'Baraka', 'last' => 'Shirima', 'dept' => 'Human Resources', 'user' => null],
            ['code' => 'EMP-0009', 'first' => 'Fatuma', 'last' => 'Ally', 'dept' => 'Registry', 'user' => null],
            ['code' => 'EMP-0010', 'first' => 'Emmanuel', 'last' => 'Massawe', 'dept' => 'Academics', 'user' => null],
            ['code' => 'EMP-0011', 'first' => 'Rehema', 'last' => 'Kessy', 'dept' => 'Finance', 'user' => null],
            ['code' => 'EMP-0012', 'first' => 'Daudi', 'last' => 'Nyerere', 'dept' => 'ICT', 'user' => null],
            ['code' => 'EMP-0013', 'first' => 'Zawadi', 'last' => 'Mahenge', 'dept' => 'Human Resources', 'user' => null],
            ['code' => 'EMP-0014', 'first' => 'Joseph', 'last' => 'Kimaro', 'dept' => 'Registry', 'user' => null],
            ['code' => 'EMP-0015', 'first' => 'Halima', 'last' => 'Rashid', 'dept' => 'Academics', 'user' => null],
            ['code' => 'EMP-0016', 'first' => 'Frank', 'last' => 'Mbwana', 'dept' => 'Finance', 'user' => null],
            ['code' => 'EMP-0017', 'first' => 'Sophia', 'last' => 'Urassa', 'dept' => 'ICT', 'user' => null],
            ['code' => 'EMP-0018', 'first' => 'Ibrahim', 'last' => 'Hassan', 'dept' => 'Human Resources', 'user' => null],
            ['code' => 'EMP-0019', 'first' => 'Mary', 'last' => 'Temba', 'dept' => 'Registry', 'user' => null],
            ['code' => 'EMP-0020', 'first' => 'Salum', 'last' => 'Omary', 'dept' => 'Academics', 'user' => null],
            ['code' => 'EMP-0021', 'first' => 'Elizabeth', 'last' => 'Swai', 'dept' => 'Finance', 'user' => null],
            ['code' => 'EMP-0022', 'first' => 'Michael', 'last' => 'Mwakalinga', 'dept' => 'ICT', 'user' => null],
            ['code' => 'EMP-0023', 'first' => 'Asha', 'last' => 'Ramadhani', 'dept' => 'Human Resources', 'user' => null],
            ['code' => 'EMP-0024', 'first' => 'Godfrey', 'last' => 'Mrema', 'dept' => 'Registry', 'user' => null],
        ];

        foreach ($sampleEmployees as $index => $data) {
            Employee::firstOrCreate(
                ['employee_code' => $data['code']],
                [
                    'user_id' => $data['user']?->id,
                    'department_id' => $departments[$data['dept']]->id,
                    'work_schedule_id' => $workSchedule->id,
                    'first_name' => $data['first'],
                    'last_name' => $data['last'],
                    // Staggered tenure (6 months – ~4 years) so the roster looks organic.
                    'hire_date' => now()->subMonths(6 + ($index * 7) % 42),
                    'status' => 'active',
                ]
            );
        }
    }
}
