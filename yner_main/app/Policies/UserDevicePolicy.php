<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\User;
use App\Models\UserDevice;

class UserDevicePolicy
{
    /**
     * Everyone may open the devices page; the controller scopes what they actually see
     * (own devices, or all of them for an Admin).
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, UserDevice $device): bool
    {
        return $this->ownerOrAdmin($user, $device);
    }

    /**
     * Registering an authenticator requires a linked, active Employee record.
     *
     * A device is only ever useful for mobile check-in, and check-in resolves through the
     * Employee. Letting an unlinked account register one would create a credential that can
     * never be used and would clutter the admin view.
     */
    public function create(User $user): bool
    {
        return $user->employee !== null && $user->employee->status === 'active';
    }

    /**
     * Revocation. An owner can always retire their own phone (lost, sold, replaced), and an
     * Admin can retire anyone's — that is the incident-response path.
     */
    public function delete(User $user, UserDevice $device): bool
    {
        return $this->ownerOrAdmin($user, $device);
    }

    private function ownerOrAdmin(User $user, UserDevice $device): bool
    {
        return $device->user_id === $user->id || $user->hasRole(RoleName::Admin->value);
    }
}
