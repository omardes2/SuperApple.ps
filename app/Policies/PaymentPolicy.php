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

    /**
     * A payment may be deleted at any status, but deleting a posted/cancelled
     * payment undoes (reverses) its GL, so it requires the same authority as
     * cancelling. A draft (no GL) only needs edit authority.
     */
    public function delete(User $user, Payment $payment): bool
    {
        return $payment->isDraft()
            ? $user->can('payments.edit')
            : $user->can('payments.cancel');
    }

    public function print(User $user, Payment $payment): bool
    {
        return $user->can('payments.print');
    }
}
