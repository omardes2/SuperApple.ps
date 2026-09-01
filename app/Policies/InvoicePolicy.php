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

    /**
     * Reopen an issued/sent invoice for editing (reverse journal → draft). Only
     * while it has no active payment allocations; a cancelled invoice is final.
     */
    public function revertToDraft(User $user, Invoice $invoice): bool
    {
        return $user->can('invoices.edit')
            && ! $invoice->isDraft()
            && ! $invoice->isCancelled()
            && ! $invoice->activeAllocations()->exists();
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

    /**
     * Delete an invoice that is NOT tied to any payment. A clean draft is
     * removed with invoices.edit; an issued/sent/unpaid/overdue or an
     * already-reversed cancelled invoice is removed (its issue journal reversed)
     * with invoices.cancel — the same right as cancelling it. NEVER deletable
     * once a payment is attached (any allocation, or a paid amount): those must
     * be handled through the payments first.
     */
    public function delete(User $user, Invoice $invoice): bool
    {
        if ((float) $invoice->paid_usd_equivalent > 0 || $invoice->allocations()->exists()) {
            return false;
        }

        return $invoice->isDraft()
            ? $user->can('invoices.edit')
            : $user->can('invoices.cancel');
    }
}
