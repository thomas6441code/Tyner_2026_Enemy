<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\User;
use App\Models\WorkSchedule;

class WorkSchedulePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([RoleName::Admin->value, RoleName::HrOfficer->value]);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, WorkSchedule $workSchedule): bool
    {
        return $user->hasAnyRole([RoleName::Admin->value, RoleName::HrOfficer->value]);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasRole(RoleName::Admin->value);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, WorkSchedule $workSchedule): bool
    {
        return $user->hasRole(RoleName::Admin->value);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, WorkSchedule $workSchedule): bool
    {
        return $user->hasRole(RoleName::Admin->value);
    }
}
