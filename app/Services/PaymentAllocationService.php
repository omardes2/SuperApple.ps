<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Support\Money;
use Brick\Math\BigDecimal;
use RuntimeException;

/**
 * Owns payment→invoice allocation: creating allocations with full accounting
 * snapshots, updating invoice balances/status, computing the exchange
 * gain/loss on the allocated portion, and reversing allocations. All invoice
 * mutations here run under a row lock (callers wrap in a transaction).
 */
class PaymentAllocationService
{
    /** Tolerance (USD) absorbed by rounding when checking full settlement. */
    private const TOLERANCE = '0.01';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Allocate part of a payment to one invoice. Assumes it runs inside the
     * posting transaction; it re-reads the invoice with a lock.
     */
    public function allocate(Payment $payment, int $invoiceId, string $allocatedUsd): PaymentAllocation
    {
        $invoice = Invoice::whereKey($invoiceId)->lockForUpdate()->firstOrFail();

        // ---- Guards ----
        if ((int) $invoice->customer_id !== (int) $payment->customer_id) {
            throw new RuntimeException('لا يمكن تخصيص دفعة عميل لفاتورة عميل آخر.');
        }
        if (! $invoice->acceptsAllocation()) {
            throw new RuntimeException("الفاتورة {$invoice->invoice_number} لا تقبل تخصيص دفعة (حالتها: {$invoice->status->label()}).");
        }
        if (Money::isZeroOrNegative($allocatedUsd)) {
            throw new RuntimeException('قيمة التخصيص يجب أن تكون أكبر من صفر.');
        }
        if (Money::isGreaterThan($allocatedUsd, $invoice->remaining_usd)) {
            throw new RuntimeException("قيمة التخصيص تتجاوز المتبقي على الفاتورة {$invoice->invoice_number}.");
        }

        // ---- Accounting snapshots + exchange difference ----
        $invoiceRate = Money::rate($invoice->exchange_rate);
        $paymentRate = Money::rate($payment->exchange_rate);
        $invoiceIls = Money::convertUsdToIls($allocatedUsd, $invoiceRate);
        $paymentIls = Money::convertUsdToIls($allocatedUsd, $paymentRate);
        $difference = Money::subtract($paymentIls, $invoiceIls); // + gain, − loss

        $allocation = $payment->allocations()->create([
            'invoice_id' => $invoice->id,
            'allocated_usd' => Money::money($allocatedUsd),
            'invoice_exchange_rate' => $invoiceRate,
            'payment_exchange_rate' => $paymentRate,
            'invoice_accounting_value_ils' => $invoiceIls,
            'payment_accounting_value_ils' => $paymentIls,
            'exchange_difference_ils' => $difference,
            'status' => PaymentAllocation::STATUS_ACTIVE,
        ]);

        $this->applyToInvoice($invoice, $allocatedUsd);

        $this->audit->log('allocation_created', $allocation, 'Payments',
            new: ['invoice' => $invoice->invoice_number, 'allocated_usd' => $allocation->allocated_usd, 'exchange_difference_ils' => $difference],
            description: "تخصيص {$allocation->allocated_usd} USD للفاتورة {$invoice->invoice_number}");

        return $allocation;
    }

    /**
     * Reverse an active allocation: restore the invoice remaining/status and
     * mark the allocation reversed (kept for history — never deleted).
     */
    public function reverse(PaymentAllocation $allocation, User $actor, ?string $reason = null): void
    {
        if (! $allocation->isActive()) {
            return;
        }

        $invoice = Invoice::whereKey($allocation->invoice_id)->lockForUpdate()->firstOrFail();

        $this->applyToInvoice($invoice, '-'.$allocation->allocated_usd);

        $allocation->update([
            'status' => PaymentAllocation::STATUS_REVERSED,
            'reversed_at' => now(),
            'reversed_by' => $actor->id,
            'reversal_reason' => $reason,
        ]);

        $this->audit->log('allocation_reversed', $allocation, 'Payments',
            old: ['allocated_usd' => $allocation->allocated_usd],
            description: "عكس تخصيص {$allocation->allocated_usd} USD عن الفاتورة {$invoice->invoice_number}");
    }

    /**
     * Apply a signed USD delta to an invoice's paid/remaining and re-derive its
     * status. Never lets remaining go below zero (rounding tolerance aside).
     */
    private function applyToInvoice(Invoice $invoice, string $deltaUsd): void
    {
        $paid = Money::add($invoice->paid_usd_equivalent, $deltaUsd);
        if (Money::isZeroOrNegative($paid)) {
            $paid = '0.00';
        }

        $remaining = Money::subtract($invoice->total_usd, $paid);
        if (Money::isZeroOrNegative($remaining) && ! Money::isGreaterThan(Money::absDiff($remaining, '0'), self::TOLERANCE)) {
            $remaining = '0.00';
        }
        if (BigDecimal::of($remaining)->isNegative()) {
            $remaining = '0.00';
            $paid = Money::money($invoice->total_usd);
        }

        $invoice->paid_usd_equivalent = $paid;
        $invoice->remaining_usd = $remaining;
        $invoice->status = $this->deriveStatus($invoice, $paid, $remaining);
        $invoice->save();
    }

    /**
     * Payment-driven status: Paid when settled, PartiallyPaid when part paid,
     * otherwise back to Sent (if it was sent) or Issued.
     */
    private function deriveStatus(Invoice $invoice, string $paid, string $remaining): InvoiceStatus
    {
        if (Money::isZeroOrNegative($remaining)) {
            return InvoiceStatus::Paid;
        }

        if (Money::isPositive($paid)) {
            return InvoiceStatus::PartiallyPaid;
        }

        return $invoice->sent_at ? InvoiceStatus::Sent : InvoiceStatus::Issued;
    }
}
