<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\AttendanceRecord;
use App\Models\User;

class AttendanceRecordPolicy
{
    /**
     * Determine whether the user can view the attendance dashboard.
     *
     * Everyone authenticated may view; the controller narrows an Employee to their own
     * record while Admin/HR see all employees.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view a specific record.
     */
    public function view(User $user, AttendanceRecord $attendanceRecord): bool
    {
        return $user->hasAnyRole([RoleName::Admin->value, RoleName::HrOfficer->value])
            || $attendanceRecord->employee?->user_id === $user->id;
    }

    /**
     * Determine whether the user can apply a manual correction.
     *
     * Records are computed, so only Admin/HR may override them; there is no create/delete.
     */
    public function update(User $user, AttendanceRecord $attendanceRecord): bool
    {
        return $user->hasAnyRole([RoleName::Admin->value, RoleName::HrOfficer->value]);
    }
}
