<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\DeviceEnrollment;
use App\Models\User;

class DeviceEnrollmentPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole(RoleName::Admin->value);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, DeviceEnrollment $deviceEnrollment): bool
    {
        return $user->hasRole(RoleName::Admin->value);
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
    public function update(User $user, DeviceEnrollment $deviceEnrollment): bool
    {
        return $user->hasRole(RoleName::Admin->value);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, DeviceEnrollment $deviceEnrollment): bool
    {
        return $user->hasRole(RoleName::Admin->value);
    }
}
