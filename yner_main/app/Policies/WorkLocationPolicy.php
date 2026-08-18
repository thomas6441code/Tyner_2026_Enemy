<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\User;
use App\Models\WorkLocation;

class WorkLocationPolicy
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
    public function view(User $user, WorkLocation $workLocation): bool
    {
        return $user->hasAnyRole([RoleName::Admin->value, RoleName::HrOfficer->value]);
    }

    /**
     * Determine whether the user can create models.
     *
     * HR may create sites: they are the ones onboarding staff to a new office or field post.
     */
    public function create(User $user): bool
    {
        return $user->hasAnyRole([RoleName::Admin->value, RoleName::HrOfficer->value]);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, WorkLocation $workLocation): bool
    {
        return $user->hasAnyRole([RoleName::Admin->value, RoleName::HrOfficer->value]);
    }

    /**
     * Determine whether the user can delete the model.
     *
     * Admin only: deleting a location nulls it out on every employee and department that
     * referenced it, silently disabling their mobile check-in.
     */
    public function delete(User $user, WorkLocation $workLocation): bool
    {
        return $user->hasRole(RoleName::Admin->value);
    }
}
