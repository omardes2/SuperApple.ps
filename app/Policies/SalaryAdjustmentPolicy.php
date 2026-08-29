<?php

namespace App\Policies;

use App\Models\SalaryAdjustment;
use App\Models\User;

class SalaryAdjustmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('salary_adjustments.view');
    }

    public function view(User $user, SalaryAdjustment $adjustment): bool
    {
        return $user->can('salary_adjustments.view');
    }

    public function manage(User $user): bool
    {
        return $user->can('salary_adjustments.manage');
    }
}
