<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Employee;
use App\Models\User;

class EmployeePolicy
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
    public function view(User $user, Employee $employee): bool
    {
        return $user->hasAnyRole([RoleName::Admin->value, RoleName::HrOfficer->value])
            || $employee->user_id === $user->id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasAnyRole([RoleName::Admin->value, RoleName::HrOfficer->value]);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Employee $employee): bool
    {
        return $user->hasAnyRole([RoleName::Admin->value, RoleName::HrOfficer->value]);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Employee $employee): bool
    {
        return $user->hasRole(RoleName::Admin->value);
    }
}
