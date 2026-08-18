<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\User;

/**
 * Two distinct capabilities, deliberately not the same audience.
 *
 * `create` is the act of checking in — every employee does it for themselves. `viewAny` is the
 * audit log of everyone's attempts, including rejected ones with their coordinates and IP
 * addresses, which is oversight data and stays with Admin and HR.
 */
class MobileCheckInPolicy
{
    /**
     * The admin log: who tried to check in, from where, and whether it was accepted.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([RoleName::Admin->value, RoleName::HrOfficer->value]);
    }

    /**
     * Checking in requires a linked, active Employee — a punch is recorded against the employee
     * record, so an account without one has nowhere to put it. Role is irrelevant here: an
     * Admin who is also an employee checks in exactly like anyone else.
     */
    public function create(User $user): bool
    {
        return $user->employee !== null && $user->employee->status === 'active';
    }
}
