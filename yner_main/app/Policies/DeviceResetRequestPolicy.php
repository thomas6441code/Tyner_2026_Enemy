<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\DeviceResetRequest;
use App\Models\User;

class DeviceResetRequestPolicy
{
    /**
     * The review queue is Admin/HR only. It names employees and the phones they carry.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([RoleName::Admin->value, RoleName::HrOfficer->value]);
    }

    public function view(User $user, DeviceResetRequest $request): bool
    {
        return $request->user_id === $user->id || $this->viewAny($user);
    }

    /**
     * Anyone with a linked, active employee record may ask. Asking is cheap and harmless — the
     * approval is where the control lives.
     */
    public function create(User $user): bool
    {
        return $user->employee !== null && $user->employee->status === 'active';
    }

    /**
     * Approve or reject, Admin/HR only, and only while still Pending.
     *
     * The Pending guard is not cosmetic: approving twice would revoke a device that has already
     * been replaced and re-open a window that was meant to be spent.
     */
    public function review(User $user, DeviceResetRequest $request): bool
    {
        return $request->isPending()
            && $user->hasAnyRole([RoleName::Admin->value, RoleName::HrOfficer->value]);
    }
}
