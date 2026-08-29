<?php

namespace App\Policies;

use App\Models\Quotation;
use App\Models\User;

class QuotationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('quotations.view');
    }

    public function view(User $user, Quotation $quotation): bool
    {
        return $user->can('quotations.view');
    }

    public function create(User $user): bool
    {
        return $user->can('quotations.create');
    }

    public function update(User $user, Quotation $quotation): bool
    {
        return $user->can('quotations.edit') && $quotation->isEditable();
    }

    public function send(User $user, Quotation $quotation): bool
    {
        return $user->can('quotations.send');
    }

    public function accept(User $user, Quotation $quotation): bool
    {
        return $user->can('quotations.accept');
    }

    public function reject(User $user, Quotation $quotation): bool
    {
        return $user->can('quotations.reject');
    }

    public function cancel(User $user, Quotation $quotation): bool
    {
        return $user->can('quotations.cancel');
    }

    public function convert(User $user, Quotation $quotation): bool
    {
        return $user->can('quotations.convert');
    }

    public function print(User $user, Quotation $quotation): bool
    {
        return $user->can('quotations.print');
    }
}
