<?php

namespace App\Policies;

use App\Models\PayrollPayment;
use App\Models\User;

class PayrollPaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('payroll_payments.view');
    }

    public function view(User $user, PayrollPayment $payment): bool
    {
        return $user->can('payroll_payments.view');
    }

    public function create(User $user): bool
    {
        return $user->can('payroll_payments.create');
    }

    public function reverse(User $user, PayrollPayment $payment): bool
    {
        return $user->can('payroll_payments.reverse') && $payment->isPosted();
    }
}
