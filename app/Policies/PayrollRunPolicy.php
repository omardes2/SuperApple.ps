<?php

namespace App\Policies;

use App\Models\PayrollRun;
use App\Models\User;

class PayrollRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('payroll.view');
    }

    public function view(User $user, PayrollRun $run): bool
    {
        return $user->can('payroll.view');
    }

    public function create(User $user): bool
    {
        return $user->can('payroll.create');
    }

    public function calculate(User $user, PayrollRun $run): bool
    {
        return $user->can('payroll.calculate') && $run->isEditable();
    }

    public function approve(User $user, PayrollRun $run): bool
    {
        return $user->can('payroll.approve') && $run->status->value === 'calculated';
    }

    public function post(User $user, PayrollRun $run): bool
    {
        return $user->can('payroll.post') && $run->status->value === 'approved';
    }

    public function reverse(User $user, PayrollRun $run): bool
    {
        return $user->can('payroll.reverse') && $run->isPosted();
    }

    public function cancel(User $user, PayrollRun $run): bool
    {
        return $user->can('payroll.manage') && ! $run->isPosted() && $run->status->value !== 'cancelled';
    }

    public function pay(User $user, PayrollRun $run): bool
    {
        return $user->can('payroll.pay') && $run->isPosted();
    }
}
