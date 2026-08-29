<?php

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\LedgerPostingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/**
 * Backfills GL journals for historical documents issued/posted before the
 * accounting module existed. It NEVER re-runs business actions — it only creates
 * accounting journals from the snapshots already on the documents. Idempotent
 * (skips anything already posted) and supports --dry-run.
 */
class AccountingBackfillCommand extends Command
{
    protected $signature = 'accounting:backfill {--dry-run : Report what would be posted without writing anything}';

    protected $description = 'Create GL journals for historical invoices and payments (idempotent).';

    public function handle(LedgerPostingService $ledger, AccountingService $accounting): int
    {
        $dry = (bool) $this->option('dry-run');
        $this->info($dry ? 'وضع المعاينة (dry-run) — لن تتم أي كتابة.' : 'تنفيذ الترحيل التاريخي...');

        // Act as a system/accountant user so created_by is populated.
        Auth::login(User::whereHas('roles', fn ($q) => $q->whereIn('name', ['Accountant', 'General Manager', 'Super Admin']))->first() ?? User::first());

        $stats = [
            'invoice_issue' => 0, 'invoice_reversal' => 0,
            'payment_receipt' => 0, 'payment_reversal' => 0, 'skipped' => 0,
        ];

        // ---- Invoices ----
        Invoice::where('status', '!=', InvoiceStatus::Draft->value)
            ->orderBy('id')->chunkById(200, function ($invoices) use ($ledger, $accounting, $dry, &$stats) {
                foreach ($invoices as $invoice) {
                    $hasIssue = $accounting->hasPosted('invoice', $invoice->id, 'invoice_issue');
                    $isCancelled = $invoice->status === InvoiceStatus::Cancelled;

                    if (! $hasIssue) {
                        $stats['invoice_issue']++;
                        if (! $dry) {
                            $ledger->postInvoiceIssue($invoice);
                        }
                    } else {
                        $stats['skipped']++;
                    }

                    // Cancelled invoice → ensure the issue journal is reversed.
                    if ($isCancelled) {
                        $issue = JournalEntry::posted()
                            ->where('source_type', 'invoice')->where('source_id', $invoice->id)
                            ->where('posting_type', 'invoice_issue')->first();
                        if (! $dry && ($issue !== null || ! $hasIssue)) {
                            $ledger->reverseInvoiceIssue($invoice, $invoice->cancellation_reason ?? 'إلغاء تاريخي');
                            $stats['invoice_reversal']++;
                        } elseif ($dry) {
                            $stats['invoice_reversal']++;
                        }
                    }
                }
            });

        // ---- Payments ----
        Payment::whereIn('status', [PaymentStatus::Posted->value, PaymentStatus::Cancelled->value])
            ->orderBy('id')->chunkById(200, function ($payments) use ($ledger, $accounting, $dry, &$stats) {
                foreach ($payments as $payment) {
                    if ($accounting->hasPosted('payment', $payment->id, 'payment_receipt')) {
                        $stats['skipped']++;

                        continue;
                    }

                    $cancelled = $payment->status === PaymentStatus::Cancelled;
                    $stats['payment_receipt']++;
                    if (! $dry) {
                        $ledger->postPaymentReceipt($payment, includeReversed: $cancelled);
                        if ($cancelled) {
                            $ledger->reversePaymentReceipt($payment, $payment->cancellation_reason ?? 'إلغاء تاريخي');
                        }
                    }
                    if ($cancelled) {
                        $stats['payment_reversal']++;
                    }
                }
            });

        Auth::logout();

        $this->table(['العملية', 'العدد'], collect($stats)->map(fn ($v, $k) => [$k, $v])->values()->all());

        if ($dry) {
            $this->warn('لم تُكتب أي قيود (dry-run).');
        } else {
            $this->info('اكتمل الترحيل التاريخي.');
        }

        return self::SUCCESS;
    }
}
