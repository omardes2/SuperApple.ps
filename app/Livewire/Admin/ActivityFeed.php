<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * A management activity feed distilled from the audit log. Each row is shown
 * only if the viewer has permission for that module, so the feed never surfaces
 * activity (e.g. financial postings) the user cannot otherwise see.
 */
#[Layout('layouts.app')]
#[Title('سجل النشاط')]
class ActivityFeed extends Component
{
    /** Significant actions worth surfacing to management. */
    private const ACTIONS = [
        'invoice_issued', 'invoice_cancelled', 'payment_posted', 'payment_cancelled',
        'journal_reversed', 'task_completed', 'leave_approved', 'payroll_posted',
        'subscription_created', 'subscription_cancelled', 'advance_paid', 'expense_posted',
        'supplier_bill_posted', 'whatsapp_send_failed',
    ];

    /** Module label → permission needed to see its activity. */
    private const MODULE_PERMS = [
        'Invoices' => 'invoices.view',
        'Payments' => 'payments.view',
        'Accounting' => 'accounting.view',
        'Expenses' => 'expenses.view',
        'Suppliers' => 'suppliers.view',
        'Payroll' => 'payroll.view',
        'Subscriptions' => 'subscriptions.view',
        'WhatsApp' => 'whatsapp.view',
        'Tasks' => 'tasks.view',
        'Leaves' => 'leaves.view',
    ];

    public function mount(): void
    {
        abort_unless(auth()->user()->canAny(['reports.operational', 'audit.view']), 403);
    }

    private function canSeeModule(?string $module): bool
    {
        $perm = self::MODULE_PERMS[$module] ?? null;

        return $perm === null ? true : auth()->user()->can($perm);
    }

    public function render()
    {
        $rows = AuditLog::query()->with('user')
            ->whereIn('action', self::ACTIONS)
            ->latest('created_at')->limit(120)->get()
            ->filter(fn ($l) => $this->canSeeModule($l->module))
            ->take(50)->values();

        return view('livewire.admin.activity-feed', ['rows' => $rows]);
    }
}
