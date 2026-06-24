<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\PermissionRequest;
use App\Models\User;

class PermissionRequestPolicy
{
    /**
     * Determine whether the user can view the permission list.
     *
     * Everyone authenticated may view; the controller narrows an Employee to their own
     * requests while Admin/HR see all.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view a specific request (and its attachment).
     */
    public function view(User $user, PermissionRequest $permissionRequest): bool
    {
        return $user->hasAnyRole([RoleName::Admin->value, RoleName::HrOfficer->value])
            || $permissionRequest->employee?->user_id === $user->id;
    }

    /**
     * Determine whether the user can submit a request.
     *
     * Requests are filed against an Employee record, so the user must be linked to one.
     */
    public function create(User $user): bool
    {
        return $user->employee()->exists();
    }

    /**
     * Determine whether the user can edit a request.
     *
     * Only the owner, and only while it is still Pending (not yet reviewed).
     */
    public function update(User $user, PermissionRequest $permissionRequest): bool
    {
        return $permissionRequest->isPending()
            && $permissionRequest->employee?->user_id === $user->id;
    }

    /**
     * Determine whether the user can cancel a request.
     *
     * Same rule as update — owner, while Pending. The controller soft-cancels (status →
     * Cancelled) rather than hard-deleting, preserving history.
     */
    public function delete(User $user, PermissionRequest $permissionRequest): bool
    {
        return $this->update($user, $permissionRequest);
    }

    /**
     * Determine whether the user can approve or reject a request.
     *
     * Admin/HR only, and only while the request is still Pending.
     */
    public function review(User $user, PermissionRequest $permissionRequest): bool
    {
        return $permissionRequest->isPending()
            && $user->hasAnyRole([RoleName::Admin->value, RoleName::HrOfficer->value]);
    }
}
