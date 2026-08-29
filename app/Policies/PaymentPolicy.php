<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('payments.view');
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->can('payments.view');
    }

    public function create(User $user): bool
    {
        return $user->can('payments.create');
    }

    public function update(User $user, Payment $payment): bool
    {
        return $user->can('payments.edit') && $payment->isDraft();
    }

    public function post(User $user, Payment $payment): bool
    {
        return $user->can('payments.post') && $payment->isDraft();
    }

    public function allocate(User $user, Payment $payment): bool
    {
        return $user->can('payments.allocate');
    }

    public function cancel(User $user, Payment $payment): bool
    {
        return $user->can('payments.cancel') && ! $payment->isCancelled();
    }

    public function print(User $user, Payment $payment): bool
    {
        return $user->can('payments.print');
    }
}
