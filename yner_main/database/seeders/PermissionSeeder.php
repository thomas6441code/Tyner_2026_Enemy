<?php

namespace Database\Seeders;

use App\Enums\PermissionStatus;
use App\Enums\PermissionType;
use App\Models\Employee;
use App\Models\PermissionRequest;
use App\Models\User;
use App\Services\AttendanceSyncService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class PermissionSeeder extends Seeder
{
    /**
     * Seed permission/leave requests spread across the roster and the last three months so HR
     * has a real queue to action and the sync engine has visibly done its job. Every approved
     * request is run through AttendanceSyncService so approved absences in the window flip from
     * Absent → the correct leave status on `attendance_records` (the core EAPMS guarantee).
     */
    public function run(): void
    {
        $hr = User::where('email', 'hr@eapms.test')->first();
        $today = Carbon::today();
        $sync = app(AttendanceSyncService::class);

        // employee_code => list of requests. Past ranges land inside the seeded 3-month window
        // so approvals overlay real leave onto days the calculator otherwise scored Absent.
        $plan = [
            'EMP-0001' => [
                ['type' => PermissionType::Permission, 'start' => 3, 'end' => 3, 'status' => PermissionStatus::Pending, 'reason' => 'Bank appointment in the afternoon.'],
                ['type' => PermissionType::SickLeave, 'start' => -5, 'end' => -4, 'status' => PermissionStatus::Approved, 'reason' => 'Flu — doctor advised two days rest.'],
                ['type' => PermissionType::OfficialLeave, 'start' => -20, 'end' => -18, 'status' => PermissionStatus::Rejected, 'reason' => 'Family event out of town.'],
            ],
            'EMP-0003' => [
                ['type' => PermissionType::OfficialLeave, 'start' => -35, 'end' => -33, 'status' => PermissionStatus::Approved, 'reason' => 'Payroll training workshop.'],
            ],
            'EMP-0005' => [
                ['type' => PermissionType::FieldDuty, 'start' => -12, 'end' => -12, 'status' => PermissionStatus::Approved, 'reason' => 'Marking centre supervision off-site.'],
            ],
            'EMP-0007' => [
                ['type' => PermissionType::SickLeave, 'start' => -48, 'end' => -46, 'status' => PermissionStatus::Approved, 'reason' => 'Admitted with malaria.'],
            ],
            'EMP-0008' => [
                ['type' => PermissionType::Permission, 'start' => -22, 'end' => -22, 'status' => PermissionStatus::Approved, 'reason' => 'Court appearance.'],
                ['type' => PermissionType::SickLeave, 'start' => 2, 'end' => 3, 'status' => PermissionStatus::Pending, 'reason' => 'Scheduled minor surgery.'],
            ],
            'EMP-0011' => [
                ['type' => PermissionType::OfficialLeave, 'start' => -60, 'end' => -56, 'status' => PermissionStatus::Approved, 'reason' => 'Annual leave.'],
            ],
            'EMP-0014' => [
                ['type' => PermissionType::FieldDuty, 'start' => -8, 'end' => -7, 'status' => PermissionStatus::Approved, 'reason' => 'Records archiving at branch office.'],
            ],
            'EMP-0016' => [
                ['type' => PermissionType::Permission, 'start' => -15, 'end' => -15, 'status' => PermissionStatus::Approved, 'reason' => 'Personal emergency.'],
                ['type' => PermissionType::Permission, 'start' => 5, 'end' => 5, 'status' => PermissionStatus::Pending, 'reason' => 'School meeting for child.'],
            ],
            'EMP-0019' => [
                ['type' => PermissionType::SickLeave, 'start' => -28, 'end' => -27, 'status' => PermissionStatus::Approved, 'reason' => 'Dental procedure recovery.'],
            ],
            'EMP-0022' => [
                ['type' => PermissionType::OfficialLeave, 'start' => -4, 'end' => -2, 'status' => PermissionStatus::Pending, 'reason' => 'Conference travel next week.'],
            ],
            'EMP-0024' => [
                ['type' => PermissionType::Permission, 'start' => -40, 'end' => -40, 'status' => PermissionStatus::Approved, 'reason' => 'Family matter.'],
                ['type' => PermissionType::SickLeave, 'start' => -10, 'end' => -9, 'status' => PermissionStatus::Approved, 'reason' => 'High fever.'],
            ],
        ];

        foreach ($plan as $code => $requests) {
            $employee = Employee::where('employee_code', $code)->first();

            if (! $employee) {
                continue;
            }

            foreach ($requests as $sample) {
                $reviewed = $sample['status'] !== PermissionStatus::Pending;
                $start = $today->copy()->addDays($sample['start']);
                $end = $today->copy()->addDays($sample['end']);

                $request = PermissionRequest::firstOrCreate(
                    [
                        'employee_id' => $employee->id,
                        'type' => $sample['type'],
                        'start_date' => $start->toDateString(),
                    ],
                    [
                        'end_date' => $end->toDateString(),
                        'reason' => $sample['reason'],
                        'status' => $sample['status'],
                        'reviewed_by' => $reviewed ? $hr?->id : null,
                        'reviewed_at' => $reviewed ? now() : null,
                        'review_note' => $sample['status'] === PermissionStatus::Rejected
                            ? 'Insufficient notice — please reapply with more lead time.'
                            : null,
                    ],
                );

                // The seeder writes rows directly, so PermissionRequestApproved never fires.
                // Sync approved requests explicitly so the Absent → leave-status flip is visible
                // on /attendance and in reports out of the box.
                if ($request->status === PermissionStatus::Approved) {
                    $sync->syncForApproval($request);
                }
            }
        }
    }
}
