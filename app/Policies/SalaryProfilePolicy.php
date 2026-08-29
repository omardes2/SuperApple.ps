<?php

namespace App\Policies;

use App\Models\EmployeeSalaryProfile;
use App\Models\User;

class SalaryProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('salary_profiles.view');
    }

    public function view(User $user, EmployeeSalaryProfile $profile): bool
    {
        return $user->can('salary_profiles.view');
    }

    public function manage(User $user): bool
    {
        return $user->can('salary_profiles.manage');
    }
}
