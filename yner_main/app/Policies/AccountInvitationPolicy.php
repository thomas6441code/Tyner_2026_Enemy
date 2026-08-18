<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\AccountInvitation;
use App\Models\User;

class AccountInvitationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([RoleName::Admin->value, RoleName::HrOfficer->value]);
    }

    public function view(User $user, AccountInvitation $accountInvitation): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can re-issue an invitation.
     *
     * Resend revokes the old token and mints a new one, so it is the recovery path for a
     * lost or expired link — but never for one that has already been redeemed.
     */
    public function resend(User $user, AccountInvitation $accountInvitation): bool
    {
        return $accountInvitation->used_at === null && $this->viewAny($user);
    }

    /**
     * Determine whether the user can revoke an invitation.
     *
     * Revocation is a soft state change (`revoked_at`), never a delete — the row is audit
     * evidence that an invitation was issued.
     */
    public function delete(User $user, AccountInvitation $accountInvitation): bool
    {
        return $this->viewAny($user);
    }
}
