<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\Concerns\BuildsLineItems;
use App\Support\CustomerSnapshot;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only sanctioned path for creating, editing, issuing and cancelling
 * invoices. Status is never set by hand elsewhere — issue()/cancel() are the
 * gates. Issued invoices are immutable (enforced again at the model layer).
 */
class InvoiceService
{
    use BuildsLineItems;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
        private readonly Settings $settings,
        private readonly LedgerPostingService $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $data
     * @param  iterable<array<string,mixed>>  $lines
     */
    public function createDraft(array $data, iterable $lines = []): Invoice
    {
        return DB::transaction(function () use ($data, $lines) {
            $prepared = $this->prepareItems($lines);
            $invoiceDate = $data['invoice_date'] ?? now()->toDateString();

            $dueDate = $data['due_date'] ?? $this->defaultDueDate($invoiceDate);
            // The invoice exchange rate is entered MANUALLY per invoice — never
            // auto-fetched from a central/latest rate. A draft may start blank.
            $rate = $data['exchange_rate'] ?? null;

            $invoice = Invoice::create([
                'invoice_number' => $data['invoice_number'] ?? $this->numbers->next('invoice'),
                'quotation_id' => $data['quotation_id'] ?? null,
                'subscription_id' => $data['subscription_id'] ?? null,
                'customer_id' => $data['customer_id'],
                'project_id' => $data['project_id'] ?? null,
                'invoice_date' => $invoiceDate,
                'due_date' => $dueDate,
                'currency' => 'USD',
                'exchange_rate' => $rate !== null ? Money::rate($rate) : null,
                'status' => InvoiceStatus::Draft,
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? $this->settings->get('finance', 'invoice_terms'),
                'paid_usd_equivalent' => '0.00',
                'remaining_usd' => $prepared['totals']['total_usd'],
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
                ...$prepared['totals'],
            ]);

            $this->writeItems($invoice, $prepared['items']);

            $this->audit->log('invoice_created', $invoice, 'Invoices', description: 'إنشاء فاتورة (مسودة)');

            return $invoice;
        });
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  iterable<array<string,mixed>>  $lines
     */
    public function updateDraft(Invoice $invoice, array $data, iterable $lines): Invoice
    {
        $this->assertDraft($invoice);

        return DB::transaction(function () use ($invoice, $data, $lines) {
            $prepared = $this->prepareItems($lines);
            $before = $invoice->only(['total_usd', 'exchange_rate', 'discount_usd', 'tax_usd']);

            $invoice->update([
                'customer_id' => $data['customer_id'] ?? $invoice->customer_id,
                'project_id' => $data['project_id'] ?? null,
                'invoice_date' => $data['invoice_date'] ?? $invoice->invoice_date,
                'due_date' => $data['due_date'] ?? $invoice->due_date,
                'exchange_rate' => array_key_exists('exchange_rate', $data) && $data['exchange_rate'] !== null
                    ? Money::rate($data['exchange_rate'])
                    : $invoice->exchange_rate,
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? $invoice->terms,
                'remaining_usd' => $prepared['totals']['total_usd'],
                'updated_by' => Auth::id(),
                ...$prepared['totals'],
            ]);

            $invoice->items()->delete();
            $this->writeItems($invoice, $prepared['items']);

            $this->audit->log('invoice_updated', $invoice, 'Invoices',
                old: $before, new: $invoice->only(array_keys($before)),
                description: 'تعديل فاتورة (مسودة)');

            return $invoice;
        });
    }

    /**
     * Issue a draft invoice: validate, recompute on the backend, snapshot the
     * ILS accounting value, initialise payment fields, then lock. The single
     * source of truth for making an invoice official.
     */
    public function issue(Invoice $invoice): Invoice
    {
        $this->assertDraft($invoice);

        return DB::transaction(function () use ($invoice) {
            $invoice->loadMissing(['items', 'customer']);

            // ---- Pre-issue validations ----
            $customer = $invoice->customer;
            if (! $customer || ! $customer->is_active) {
                throw new RuntimeException('لا يمكن إصدار فاتورة لعميل غير نشط.');
            }
            if ($invoice->items->isEmpty()) {
                throw new RuntimeException('لا يمكن إصدار فاتورة بدون بنود.');
            }
            if (blank($invoice->invoice_date)) {
                throw new RuntimeException('تاريخ الفاتورة مطلوب.');
            }
            if ($invoice->currency !== 'USD') {
                throw new RuntimeException('عملة الفاتورة يجب أن تكون USD.');
            }
            if ($invoice->exchange_rate === null || Money::isZeroOrNegative($invoice->exchange_rate)) {
                throw new RuntimeException('سعر الصرف مطلوب ويجب أن يكون أكبر من صفر.');
            }

            // ---- Backend recomputation of totals from the item snapshots ----
            $recomputed = $this->prepareItems($invoice->items->map(fn ($i) => [
                'service_id' => $i->service_id,
                'item_name' => $i->item_name,
                'description' => $i->description,
                'quantity' => $i->quantity,
                'unit_price_usd' => $i->unit_price_usd,
                'discount_type' => $i->discount_type?->value,
                'discount_value' => $i->discount_value,
                'tax_rate' => $i->tax_rate,
            ]))['totals'];

            $rate = Money::rate($invoice->exchange_rate);
            $totalUsd = $recomputed['total_usd'];
            $totalIls = Money::convertUsdToIls($totalUsd, $rate);

            // The update runs while the original status is still Draft, so the
            // immutability guard permits writing the (now frozen) fields.
            $invoice->update([
                'subtotal_usd' => $recomputed['subtotal_usd'],
                'discount_usd' => $recomputed['discount_usd'],
                'tax_usd' => $recomputed['tax_usd'],
                'total_usd' => $totalUsd,
                'exchange_rate' => $rate,
                'total_ils_at_issue' => $totalIls,
                'paid_usd_equivalent' => '0.00',
                'remaining_usd' => $totalUsd,
                'customer_snapshot' => CustomerSnapshot::for($customer),
                'status' => InvoiceStatus::Issued,
                'issued_at' => now(),
                'updated_by' => Auth::id(),
            ]);

            $this->audit->log('invoice_issued', $invoice, 'Invoices',
                new: [
                    'total_usd' => $totalUsd,
                    'exchange_rate' => $rate,
                    'total_ils_at_issue' => $totalIls,
                ],
                description: "إصدار فاتورة بقيمة {$totalUsd} USD (سعر صرف {$rate})");

            // GL posting is part of issuing: if it fails, the whole issue rolls
            // back (an invoice can never be Issued without its journal).
            $this->ledger->postInvoiceIssue($invoice->refresh());

            return $invoice;
        });
    }

    public function send(Invoice $invoice): Invoice
    {
        if (! in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::Sent], true)) {
            throw new RuntimeException('يمكن إرسال الفواتير الصادرة فقط.');
        }

        $invoice->update(['status' => InvoiceStatus::Sent, 'sent_at' => now(), 'updated_by' => Auth::id()]);
        $this->audit->log('invoice_sent', $invoice, 'Invoices', description: 'إرسال الفاتورة');

        return $invoice;
    }

    public function cancel(Invoice $invoice, string $reason): Invoice
    {
        if ($invoice->status === InvoiceStatus::Cancelled) {
            throw new RuntimeException('الفاتورة ملغاة بالفعل.');
        }
        if (blank($reason)) {
            throw new RuntimeException('يجب إدخال سبب الإلغاء.');
        }
        // An invoice with active payment allocations cannot be cancelled — the
        // payments must be cancelled/reversed first.
        if ($invoice->activeAllocations()->exists()) {
            throw new RuntimeException('لا يمكن إلغاء فاتورة لها دفعات مخصصة نشطة. ألغِ الدفعات أولاً.');
        }

        return DB::transaction(function () use ($invoice, $reason) {
            // Reverse the issue journal (if the invoice was issued and posted).
            $this->ledger->reverseInvoiceIssue($invoice, $reason);

            $invoice->update([
                'status' => InvoiceStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'cancelled_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            $this->audit->log('invoice_cancelled', $invoice, 'Invoices',
                new: ['reason' => $reason], description: 'إلغاء الفاتورة');

            return $invoice;
        });
    }

    /**
     * Reopen an issued/sent invoice for editing the accounting-correct way:
     * reverse its issue journal and move it back to Draft, keeping the same
     * invoice number. Only permitted while NO payment is allocated against it —
     * otherwise the payments must be reversed first. After editing, the invoice
     * is re-issued (a fresh journal is posted). Never touches a paid/cancelled
     * document's history silently: the reversal is recorded and audited.
     */
    public function revertToDraft(Invoice $invoice): Invoice
    {
        if ($invoice->isDraft()) {
            return $invoice; // already editable
        }
        if ($invoice->isCancelled()) {
            throw new RuntimeException('لا يمكن تعديل فاتورة ملغاة.');
        }
        if ($invoice->activeAllocations()->exists()) {
            throw new RuntimeException('لا يمكن تعديل فاتورة لها دفعات مخصصة نشطة. ألغِ الدفعات أولاً.');
        }

        return DB::transaction(function () use ($invoice) {
            // Reverse the issue journal (Dr AR / Cr revenue+tax) — kept as history.
            $this->ledger->reverseInvoiceIssue($invoice, 'إرجاع الفاتورة إلى مسودة للتعديل');

            $invoice->update([
                'status' => InvoiceStatus::Draft,
                'issued_at' => null,
                'sent_at' => null,
                'paid_usd_equivalent' => '0.00',
                'remaining_usd' => '0.00', // recomputed on re-issue
                'updated_by' => Auth::id(),
            ]);

            $this->audit->log('invoice_reverted_to_draft', $invoice, 'Invoices',
                description: "إرجاع الفاتورة {$invoice->invoice_number} إلى مسودة للتعديل (عُكس القيد)");

            return $invoice;
        });
    }

    /**
     * Hard-delete a DRAFT invoice. Permitted only for a draft that never left
     * the drawing board: no payment allocations and no posted GL journal. An
     * issued/sent/paid/cancelled invoice is NEVER hard-deleted — those are
     * reversed via cancel(). Items are removed with the invoice and the deletion
     * is written to the audit trail before the row disappears.
     */
    public function deleteDraft(Invoice $invoice): void
    {
        if (! $invoice->isDraft()) {
            throw new RuntimeException('لا يمكن حذف فاتورة صادرة. استخدم إلغاء الفاتورة بدلاً من الحذف.');
        }
        if ($invoice->allocations()->exists()) {
            throw new RuntimeException('لا يمكن حذف فاتورة لها دفعات مرتبطة بها.');
        }
        // Belt-and-braces: a draft has no issue journal, but never delete one
        // that somehow carries a posted entry.
        if ($this->ledger->hasInvoiceJournal($invoice)) {
            throw new RuntimeException('لا يمكن حذف فاتورة لها قيود محاسبية مرحّلة.');
        }

        DB::transaction(function () use ($invoice) {
            $number = $invoice->invoice_number;

            $this->audit->log('invoice_deleted', $invoice, 'Invoices',
                old: ['invoice_number' => $number, 'status' => $invoice->status->value, 'total_usd' => $invoice->total_usd],
                description: "حذف مسودة الفاتورة {$number}");

            $invoice->items()->delete();
            $invoice->delete();
        });
    }

    private function defaultDueDate(string $invoiceDate): string
    {
        $days = (int) $this->settings->get('finance', 'default_invoice_due_days', 30);

        return Carbon::parse($invoiceDate)->addDays($days)->toDateString();
    }

    private function assertDraft(Invoice $invoice): void
    {
        if (! $invoice->isDraft()) {
            throw new RuntimeException('لا يمكن تعديل فاتورة صادرة. ألغِ الفاتورة وأصدر فاتورة جديدة.');
        }
    }

    /**
     * @param  list<array<string,mixed>>  $items
     */
    private function writeItems(Invoice $invoice, array $items): void
    {
        foreach ($items as $item) {
            $invoice->items()->create($item);
        }
    }
}
