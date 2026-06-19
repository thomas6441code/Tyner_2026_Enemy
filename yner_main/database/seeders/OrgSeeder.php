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

        $sampleEmployees = [
            ['code' => 'EMP-0001', 'first' => 'Jane', 'last' => 'Mwakasege', 'dept' => 'Finance', 'user' => $employeeUser],
            ['code' => 'EMP-0002', 'first' => 'John', 'last' => 'Kibwana', 'dept' => 'ICT', 'user' => null],
            ['code' => 'EMP-0003', 'first' => 'Amina', 'last' => 'Said', 'dept' => 'Human Resources', 'user' => null],
            ['code' => 'EMP-0004', 'first' => 'Peter', 'last' => 'Mushi', 'dept' => 'Registry', 'user' => null],
            ['code' => 'EMP-0005', 'first' => 'Grace', 'last' => 'Lyimo', 'dept' => 'Academics', 'user' => null],
        ];

        foreach ($sampleEmployees as $data) {
            Employee::firstOrCreate(
                ['employee_code' => $data['code']],
                [
                    'user_id' => $data['user']?->id,
                    'department_id' => $departments[$data['dept']]->id,
                    'work_schedule_id' => $workSchedule->id,
                    'first_name' => $data['first'],
                    'last_name' => $data['last'],
                    'hire_date' => now()->subYears(2),
                    'status' => 'active',
                ]
            );
        }
    }
}
