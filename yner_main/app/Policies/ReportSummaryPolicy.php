<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\User;

class ReportSummaryPolicy
{
    /**
     * AI report summaries are management decision-support: Admin and HR Officer only.
     * Employees do not see the org-/department-wide narratives.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([RoleName::Admin->value, RoleName::HrOfficer->value]);
    }

    /**
     * Generating a summary triggers an external Claude API call, so it is gated to the same
     * management audience.
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }
}
