<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

/**
 * Model-level authorization for invoices. Every ability maps to a granular
 * financial permission; business-state rules (draft-only edits, etc.) stay in
 * InvoiceService. Super Admin is short-circuited by Gate::before.
 */
class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('invoices.view');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $user->can('invoices.view');
    }

    public function create(User $user): bool
    {
        return $user->can('invoices.create');
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $user->can('invoices.edit') && $invoice->isDraft();
    }

    public function issue(User $user, Invoice $invoice): bool
    {
        return $user->can('invoices.issue') && $invoice->isDraft();
    }

    public function send(User $user, Invoice $invoice): bool
    {
        return $user->can('invoices.send');
    }

    public function cancel(User $user, Invoice $invoice): bool
    {
        return $user->can('invoices.cancel') && ! $invoice->isCancelled();
    }

    public function print(User $user, Invoice $invoice): bool
    {
        return $user->can('invoices.print');
    }
}
