<?php

namespace App\Policies;

use App\Models\EmployeeAdvance;
use App\Models\User;

class EmployeeAdvancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('advances.view');
    }

    public function view(User $user, EmployeeAdvance $advance): bool
    {
        return $user->can('advances.view');
    }

    public function create(User $user): bool
    {
        return $user->can('advances.create');
    }

    public function approve(User $user, EmployeeAdvance $advance): bool
    {
        return $user->can('advances.approve') && $advance->isDraft();
    }

    public function pay(User $user, EmployeeAdvance $advance): bool
    {
        return $user->can('advances.pay') && $advance->status->value === 'approved';
    }

    public function cancel(User $user, EmployeeAdvance $advance): bool
    {
        return $user->can('advances.manage') && ! $advance->isCancelled();
    }
}
