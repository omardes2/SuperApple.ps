<?php

namespace App\Services;

use App\Enums\PaymentCurrency;
use App\Enums\PaymentStatus;
use App\Models\CustomerOpeningBalance;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The single entry point for the payment lifecycle. USD is the official
 * receivable; ILS payments convert via their own payment-date rate (never the
 * invoice rate). Posting is atomic and locks the payment; corrections happen
 * through cancel(), which reverses allocations. Status is never set by hand.
 */
class PaymentService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly PaymentAllocationService $allocator,
        private readonly AuditLogger $audit,
        private readonly LedgerPostingService $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $data
     */
    public function createDraft(array $data): Payment
    {
        $currency = $data['payment_currency'] ?? 'USD';
        $rate = $data['exchange_rate'] ?? null;
        $amount = $data['payment_amount'] ?? 0;

        $payment = Payment::create([
            'payment_number' => $data['payment_number'] ?? $this->numbers->next('payment'),
            'customer_id' => $data['customer_id'] ?? null,
            'payment_date' => $data['payment_date'] ?? now()->toDateString(),
            'payment_currency' => $currency,
            'payment_amount' => Money::money($amount),
            'exchange_rate' => $rate !== null ? Money::rate($rate) : null,
            'usd_equivalent' => $this->usdEquivalent($currency, $amount, $rate),
            'payment_method' => $data['payment_method'] ?? 'cash',
            'account_id' => $data['account_id'] ?? null,
            'reference_number' => $data['reference_number'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => PaymentStatus::Draft,
            'received_by' => $data['received_by'] ?? Auth::id(),
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        $this->audit->log('payment_created', $payment, 'Payments', description: 'إنشاء دفعة (مسودة)');

        return $payment;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function updateDraft(Payment $payment, array $data): Payment
    {
        $this->assertDraft($payment);

        $currency = $data['payment_currency'] ?? $payment->payment_currency->value;
        $amount = $data['payment_amount'] ?? $payment->payment_amount;
        $rate = array_key_exists('exchange_rate', $data) ? $data['exchange_rate'] : $payment->exchange_rate;

        $payment->update([
            'customer_id' => $data['customer_id'] ?? $payment->customer_id,
            'payment_date' => $data['payment_date'] ?? $payment->payment_date,
            'payment_currency' => $currency,
            'payment_amount' => Money::money($amount),
            'exchange_rate' => $rate !== null && $rate !== '' ? Money::rate($rate) : null,
            'usd_equivalent' => $this->usdEquivalent($currency, $amount, $rate),
            'payment_method' => $data['payment_method'] ?? $payment->payment_method->value,
            'account_id' => $data['account_id'] ?? $payment->account_id,
            'reference_number' => $data['reference_number'] ?? $payment->reference_number,
            'notes' => $data['notes'] ?? $payment->notes,
            'updated_by' => Auth::id(),
        ]);

        return $payment;
    }

    /**
     * Post a payment and its allocations atomically, then lock it.
     *
     * @param  list<array{invoice_id:int,allocated_usd:int|string|float}>  $allocations
     */
    public function post(Payment $payment, array $allocations = []): Payment
    {
        $this->assertDraft($payment);

        return DB::transaction(function () use ($payment, $allocations) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            // ---- Validations ----
            if (! $payment->customer) {
                throw new RuntimeException('العميل غير موجود.');
            }
            if (blank($payment->payment_date)) {
                throw new RuntimeException('تاريخ الدفعة مطلوب.');
            }
            if (Money::isZeroOrNegative($payment->payment_amount)) {
                throw new RuntimeException('مبلغ الدفعة يجب أن يكون أكبر من صفر.');
            }

            // An ILS payment without a manually entered rate is converted at the
            // rate of the invoice (or opening balance) it settles — the customer's
            // official balance is USD, and paying at the document's own rate means
            // the AR relief in ILS equals the shekels received (no FX difference).
            if ($payment->payment_currency === PaymentCurrency::ILS
                && ($payment->exchange_rate === null || Money::isZeroOrNegative($payment->exchange_rate))) {
                $derived = $this->deriveInvoiceRate($allocations);
                if ($derived === null) {
                    throw new RuntimeException('دفعة بالشيكل يجب تخصيصها على فاتورة أو رصيد افتتاحي لتحديد سعر الصرف حسب الفاتورة.');
                }
                $payment->exchange_rate = $derived;
                $payment->save();
            }

            if ($payment->exchange_rate === null || Money::isZeroOrNegative($payment->exchange_rate)) {
                throw new RuntimeException('سعر الصرف مطلوب ويجب أن يكون أكبر من صفر.');
            }

            // The receiving cash/bank account is mandatory: without it the ledger
            // cannot attribute the receipt to an operational account and the
            // /admin/cash-banks balance would never move.
            $account = $payment->account_id ? FinancialAccount::find($payment->account_id) : null;
            if ($account === null) {
                throw new RuntimeException('يجب تحديد حساب الإيداع (صندوق/بنك) قبل ترحيل الدفعة.');
            }
            if (! $account->is_active) {
                throw new RuntimeException('حساب الإيداع غير نشط.');
            }
            if ($account->currency !== $payment->payment_currency->value) {
                throw new RuntimeException("عملة حساب الإيداع ({$account->currency}) لا تطابق عملة الدفعة ({$payment->payment_currency->value}).");
            }

            // Recompute the USD equivalent authoritatively.
            $usd = $this->usdEquivalent($payment->payment_currency->value, $payment->payment_amount, $payment->exchange_rate);
            $payment->usd_equivalent = $usd;

            // No over-allocation of the payment itself.
            $totalAllocated = Money::sum(array_map(fn ($a) => $a['allocated_usd'], $allocations));
            if (Money::isGreaterThan($totalAllocated, $usd)) {
                throw new RuntimeException('مجموع التخصيصات يتجاوز قيمة الدفعة.');
            }

            foreach ($allocations as $alloc) {
                if (Money::isZeroOrNegative($alloc['allocated_usd'])) {
                    continue;
                }
                // An allocation targets either an invoice or an opening balance.
                if (! empty($alloc['opening_balance_id'])) {
                    $this->allocator->allocateOpeningBalance($payment, (int) $alloc['opening_balance_id'], Money::money($alloc['allocated_usd']));
                } else {
                    $this->allocator->allocate($payment, (int) $alloc['invoice_id'], Money::money($alloc['allocated_usd']));
                }
            }

            $payment->status = PaymentStatus::Posted;
            $payment->posted_at = now();
            $payment->received_by = $payment->received_by ?? Auth::id();
            $payment->updated_by = Auth::id();
            $payment->save();

            $this->audit->log('payment_posted', $payment, 'Payments',
                new: ['usd_equivalent' => $usd, 'allocated_usd' => $totalAllocated, 'unallocated_usd' => Money::subtract($usd, $totalAllocated)],
                description: "ترحيل دفعة {$payment->payment_number} بقيمة {$usd} USD");

            // GL posting is part of posting the payment: any failure rolls the
            // whole thing back (a payment is never Posted without its journal).
            $this->ledger->postPaymentReceipt($payment->refresh());

            return $payment;
        });
    }

    public function cancel(Payment $payment, User $actor, string $reason): Payment
    {
        if ($payment->isCancelled()) {
            throw new RuntimeException('الدفعة ملغاة بالفعل.');
        }
        if (blank($reason)) {
            throw new RuntimeException('يجب إدخال سبب الإلغاء.');
        }

        return DB::transaction(function () use ($payment, $actor, $reason) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            foreach ($payment->activeAllocations()->get() as $allocation) {
                $this->allocator->reverse($allocation, $actor, 'إلغاء الدفعة: '.$reason);
            }

            // Reverse the GL journal before flipping status (kept as history).
            $this->ledger->reversePaymentReceipt($payment, $reason);

            $payment->update([
                'status' => PaymentStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancellation_reason' => $reason,
                'updated_by' => $actor->id,
            ]);

            $this->audit->log('payment_cancelled', $payment, 'Payments',
                new: ['reason' => $reason], description: 'إلغاء وعكس الدفعة');

            return $payment;
        });
    }

    /**
     * Permanently delete a DRAFT payment. Accounting-safe: a draft has never been
     * posted, so it carries no journal entry and no persisted allocations — there
     * is nothing to reverse.
     */
    public function deleteDraft(Payment $payment): void
    {
        if (! $payment->isDraft()) {
            throw new RuntimeException('لا يمكن حذف دفعة مُرحّلة أو ملغاة. استخدم الإلغاء (عكس القيد) للدفعات المُرحّلة.');
        }

        DB::transaction(function () use ($payment) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            // Defensive: a draft should have no posted GL entry. Never delete a
            // payment that somehow carries ledger history.
            if (! $payment->status->isDraft()) {
                throw new RuntimeException('تغيّرت حالة الدفعة؛ لا يمكن حذفها.');
            }

            // Any allocation rows (drafts normally have none) are detached first.
            $payment->allocations()->delete();

            $this->audit->log('payment_draft_deleted', $payment, 'Payments',
                old: ['payment_number' => $payment->payment_number, 'customer_id' => $payment->customer_id],
                description: "حذف مسودة دفعة {$payment->payment_number}");

            $payment->delete();
        });
    }

    /**
     * Delete a payment of ANY status, undoing its accounting effect first.
     *
     * A draft is simply removed. A POSTED payment is fully reversed before the
     * record is deleted: every active allocation is reversed (the invoices and
     * opening balances it settled are restored) and its GL receipt journal is
     * reversed by a mirror entry, so the net accounting effect is zero and the
     * books stay balanced. A CANCELLED payment was already reversed, so nothing
     * is undone again.
     *
     * Posted journal entries are never physically destroyed (that is forbidden by
     * the ledger and by accounting principle) — they are reversed. Once the
     * payment row is deleted, its allocations cascade away and the (now net-zero)
     * journal lines are detached (payment_id set null), leaving a balanced,
     * self-cancelling audit trail behind.
     */
    public function delete(Payment $payment, User $actor): void
    {
        DB::transaction(function () use ($payment, $actor) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            // Undo the accounting effect of a posted payment before removing it.
            if ($payment->status === PaymentStatus::Posted) {
                foreach ($payment->activeAllocations()->get() as $allocation) {
                    $this->allocator->reverse($allocation, $actor, 'حذف الدفعة');
                }
                // Mirror-reverse the receipt journal (Dr/Cr flipped) → net zero.
                $this->ledger->reversePaymentReceipt($payment, 'حذف الدفعة');
            }

            $this->audit->log('payment_deleted', $payment, 'Payments',
                old: [
                    'payment_number' => $payment->payment_number,
                    'status' => $payment->status->value,
                    'customer_id' => $payment->customer_id,
                    'usd_equivalent' => $payment->usd_equivalent,
                ],
                description: "حذف الدفعة {$payment->payment_number} وعكس قيودها المحاسبية");

            // Cascade removes allocation rows; journal_entry_lines.payment_id is
            // nulled (nullOnDelete). The balanced reversed journals remain.
            $payment->delete();
        });
    }

    /**
     * Suggested allocation of a payment's unallocated USD across the customer's
     * open receivables. The opening balance (the oldest pre-system debt) is
     * settled first, then invoices by oldest DUE date (then invoice date).
     *
     * @return list<array{invoice_id?:int,opening_balance_id?:int,invoice_number:string,remaining_usd:string,allocated_usd:string}>
     */
    public function autoAllocatePlan(Payment $payment): array
    {
        $available = $payment->unallocatedUsd();
        $plan = [];

        // Opening balance first — it predates every invoice.
        $ob = CustomerOpeningBalance::where('customer_id', $payment->customer_id)
            ->posted()->debit()->where('remaining_usd', '>', 0)->first();
        if ($ob && Money::isPositive($available)) {
            $take = Money::isGreaterThan($ob->remaining_usd, $available) ? $available : Money::money($ob->remaining_usd);
            $plan[] = [
                'opening_balance_id' => $ob->id,
                'invoice_number' => 'رصيد افتتاحي',
                'remaining_usd' => Money::money($ob->remaining_usd),
                'allocated_usd' => $take,
            ];
            $available = Money::subtract($available, $take);
        }

        $invoices = Invoice::where('customer_id', $payment->customer_id)
            ->whereIn('status', ['issued', 'sent', 'partially_paid'])
            ->where('remaining_usd', '>', 0)
            ->orderByRaw('due_date is null, due_date asc')
            ->orderBy('invoice_date')
            ->get();

        foreach ($invoices as $invoice) {
            if (Money::isZeroOrNegative($available)) {
                break;
            }
            $take = Money::isGreaterThan($invoice->remaining_usd, $available) ? $available : Money::money($invoice->remaining_usd);
            $plan[] = [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'remaining_usd' => Money::money($invoice->remaining_usd),
                'allocated_usd' => $take,
            ];
            $available = Money::subtract($available, $take);
        }

        return $plan;
    }

    /**
     * The exchange rate to convert an ILS payment, taken from the first invoice
     * (or opening balance) it is allocated to. Returns null when the payment has
     * no allocation target to borrow a rate from.
     *
     * @param  list<array<string,mixed>>  $allocations
     */
    private function deriveInvoiceRate(array $allocations): ?string
    {
        foreach ($allocations as $alloc) {
            if (! empty($alloc['invoice_id'])) {
                $invoice = Invoice::find($alloc['invoice_id']);
                if ($invoice && Money::isPositive($invoice->exchange_rate)) {
                    return Money::rate($invoice->exchange_rate);
                }
            }
            if (! empty($alloc['opening_balance_id'])) {
                $ob = CustomerOpeningBalance::find($alloc['opening_balance_id']);
                if ($ob && Money::isPositive($ob->exchange_rate)) {
                    return Money::rate($ob->exchange_rate);
                }
            }
        }

        return null;
    }

    /**
     * USD value of a payment: amount for USD; amount ÷ rate for ILS.
     */
    private function usdEquivalent(string $currency, int|string|float $amount, int|string|float|null $rate): string
    {
        if ($currency === PaymentCurrency::USD->value) {
            return Money::money($amount);
        }

        if ($rate === null || $rate === '' || Money::isZeroOrNegative($rate)) {
            return '0.00';
        }

        return Money::convertIlsToUsd($amount, $rate);
    }

    private function assertDraft(Payment $payment): void
    {
        if (! $payment->isDraft()) {
            throw new RuntimeException('لا يمكن تعديل دفعة مُرحّلة. ألغِ الدفعة وأنشئ دفعة جديدة.');
        }
    }
}
