<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\DeviceResetRequest;
use App\Models\User;
use App\Models\UserDevice;

class UserDevicePolicy
{
    /**
     * Everyone may open the devices page; the controller scopes what they actually see
     * (their own device, or all of them for an Admin).
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
     * Registering an authenticator requires a linked, active Employee record AND an empty
     * binding slot.
     *
     * The employee check is the old rule: a device is only useful for mobile check-in, and
     * check-in resolves through the Employee, so an unlinked account would create a credential
     * that can never be used.
     *
     * The slot check is the new one. An account is linked to exactly one device, so a second
     * registration is only permitted when the first is gone — and the only sanctioned way for
     * it to be gone is an approved device reset. Note the ordering: having an active device is
     * refused even with an approved reset, because approval revokes the outgoing device as part
     * of the same transaction. If a device is still active, something went wrong upstream and
     * the safe answer is no.
     */
    public function create(User $user): bool
    {
        if ($user->employee === null || $user->employee->status !== 'active') {
            return false;
        }

        if (UserDevice::boundTo($user->id) !== null) {
            return false;
        }

        // First device ever: nothing to reset, nothing to approve.
        if (! UserDevice::query()->where('user_id', $user->id)->exists()) {
            return true;
        }

        // Replacing one: an Admin or HR Officer must have opened a window, and it must still
        // be open and unspent.
        return DeviceResetRequest::query()
            ->where('user_id', $user->id)
            ->usable()
            ->exists();
    }

    /**
     * Revocation is Admin-only. Owners deliberately cannot unlink their own phone.
     *
     * This is the hinge the whole design turns on. If an employee could revoke and immediately
     * re-register, "one account, one device" would be a two-click formality — hand the phone
     * over, unlink, re-link, repeat. Owners use the device reset request instead, which puts a
     * human decision and a permanent record between them and a new binding.
     *
     * HR Officers can approve resets (which revokes as a side effect) but cannot revoke
     * directly: revoking without an approved replacement path just strands the employee.
     */
    public function delete(User $user, UserDevice $device): bool
    {
        return $user->hasRole(RoleName::Admin->value);
    }

    private function ownerOrAdmin(User $user, UserDevice $device): bool
    {
        return $device->user_id === $user->id || $user->hasRole(RoleName::Admin->value);
    }
}
