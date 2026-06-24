<?php

namespace Database\Seeders;

use App\Enums\PermissionStatus;
use App\Enums\PermissionType;
use App\Models\Employee;
use App\Models\PermissionRequest;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class PermissionSeeder extends Seeder
{
    /**
     * Seed a few sample requests for the linked employee so HR has something to action and
     * every status badge is represented out of the box.
     */
    public function run(): void
    {
        $employee = Employee::where('employee_code', 'EMP-0001')->first();

        if (! $employee) {
            return;
        }

        $hr = User::where('email', 'hr@eapms.test')->first();
        $today = Carbon::today();

        $samples = [
            [
                'type' => PermissionType::Permission,
                'start' => $today->copy()->addDays(3),
                'end' => $today->copy()->addDays(3),
                'reason' => 'Bank appointment in the afternoon.',
                'status' => PermissionStatus::Pending,
            ],
            [
                'type' => PermissionType::SickLeave,
                'start' => $today->copy()->subDays(5),
                'end' => $today->copy()->subDays(4),
                'reason' => 'Flu — doctor advised two days rest.',
                'status' => PermissionStatus::Approved,
            ],
            [
                'type' => PermissionType::OfficialLeave,
                'start' => $today->copy()->subDays(20),
                'end' => $today->copy()->subDays(18),
                'reason' => 'Family event out of town.',
                'status' => PermissionStatus::Rejected,
            ],
        ];

        foreach ($samples as $sample) {
            $reviewed = $sample['status'] !== PermissionStatus::Pending;

            PermissionRequest::firstOrCreate(
                [
                    'employee_id' => $employee->id,
                    'type' => $sample['type'],
                    'start_date' => $sample['start']->toDateString(),
                ],
                [
                    'end_date' => $sample['end']->toDateString(),
                    'reason' => $sample['reason'],
                    'status' => $sample['status'],
                    'reviewed_by' => $reviewed ? $hr?->id : null,
                    'reviewed_at' => $reviewed ? now() : null,
                    'review_note' => $sample['status'] === PermissionStatus::Rejected
                        ? 'Insufficient notice — please reapply with more lead time.'
                        : null,
                ],
            );
        }
    }
}
