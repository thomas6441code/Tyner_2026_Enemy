<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\RegistrationRequest;
use App\Models\User;

class RegistrationRequestPolicy
{
    /**
     * Determine whether the user can view the registration request queue.
     *
     * Unlike PermissionRequestPolicy this is NOT open to everyone — the queue contains
     * personal details of people who are not yet employees.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([RoleName::Admin->value, RoleName::HrOfficer->value]);
    }

    public function view(User $user, RegistrationRequest $registrationRequest): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can approve or reject a request.
     *
     * Admin/HR only, and only while it is still Pending — approval creates an Employee and
     * issues an invitation, so a second approval would duplicate both.
     */
    public function review(User $user, RegistrationRequest $registrationRequest): bool
    {
        return $registrationRequest->isPending()
            && $user->hasAnyRole([RoleName::Admin->value, RoleName::HrOfficer->value]);
    }

    public function delete(User $user, RegistrationRequest $registrationRequest): bool
    {
        return $user->hasRole(RoleName::Admin->value);
    }
}
