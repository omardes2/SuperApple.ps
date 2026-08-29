<?php

namespace App\Policies;

use App\Models\SupplierBill;
use App\Models\User;

class SupplierBillPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('supplier_bills.view');
    }

    public function view(User $user, SupplierBill $bill): bool
    {
        return $user->can('supplier_bills.view');
    }

    public function create(User $user): bool
    {
        return $user->can('supplier_bills.create');
    }

    public function update(User $user, SupplierBill $bill): bool
    {
        return $user->can('supplier_bills.edit') && $bill->isDraft();
    }

    public function post(User $user, SupplierBill $bill): bool
    {
        return $user->can('supplier_bills.post') && $bill->isDraft();
    }

    public function cancel(User $user, SupplierBill $bill): bool
    {
        return $user->can('supplier_bills.cancel') && ! $bill->isCancelled();
    }
}
