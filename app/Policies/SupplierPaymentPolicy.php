<?php

namespace App\Policies;

use App\Models\SupplierPayment;
use App\Models\User;

class SupplierPaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('supplier_payments.view');
    }

    public function view(User $user, SupplierPayment $payment): bool
    {
        return $user->can('supplier_payments.view');
    }

    public function create(User $user): bool
    {
        return $user->can('supplier_payments.create');
    }

    public function post(User $user, SupplierPayment $payment): bool
    {
        return $user->can('supplier_payments.post') && $payment->isDraft();
    }

    public function cancel(User $user, SupplierPayment $payment): bool
    {
        return $user->can('supplier_payments.cancel') && ! $payment->isCancelled();
    }
}
