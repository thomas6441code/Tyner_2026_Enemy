<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\User;

class AiAnomalyPolicy
{
    /**
     * AI insights (anomalies + risk scores) are decision-support for management: Admin and
     * HR Officer only. Employees do not see the org-wide analytics.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([RoleName::Admin->value, RoleName::HrOfficer->value]);
    }
}
