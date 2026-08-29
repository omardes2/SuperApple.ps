<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Support\Money;

/**
 * The customer's official balance is always in USD. Three figures are kept
 * distinct and never conflated:
 *   - Outstanding = Σ remaining_usd of non-cancelled issued invoices.
 *   - Unallocated credit = Σ (posted payment usd_equivalent − its active allocations).
 *   - Net balance = Outstanding − Unallocated credit.
 * An estimated ILS value at today's rate may be shown, clearly marked as an
 * estimate — never the official balance.
 */
class CustomerBalanceService
{
    public function __construct(private readonly ExchangeRateService $rates) {}

    public function outstandingUsd(Customer $customer): string
    {
        $sum = $customer->invoices()
            ->where('status', '!=', InvoiceStatus::Cancelled->value)
            ->where('status', '!=', InvoiceStatus::Draft->value)
            ->sum('remaining_usd');

        return Money::money($sum);
    }

    public function unallocatedCreditUsd(Customer $customer): string
    {
        $paid = Payment::where('customer_id', $customer->id)->posted()->sum('usd_equivalent');

        $allocated = PaymentAllocation::active()
            ->whereHas('payment', fn ($q) => $q->where('customer_id', $customer->id)->posted())
            ->sum('allocated_usd');

        return Money::subtract($paid, $allocated);
    }

    public function netBalanceUsd(Customer $customer): string
    {
        return Money::subtract($this->outstandingUsd($customer), $this->unallocatedCreditUsd($customer));
    }

    public function totalInvoicedUsd(Customer $customer): string
    {
        $sum = $customer->invoices()
            ->where('status', '!=', InvoiceStatus::Cancelled->value)
            ->where('status', '!=', InvoiceStatus::Draft->value)
            ->sum('total_usd');

        return Money::money($sum);
    }

    public function totalPaymentsUsd(Customer $customer): string
    {
        return Money::money(Payment::where('customer_id', $customer->id)->posted()->sum('usd_equivalent'));
    }

    /**
     * Informational only — clearly not the official balance.
     */
    public function estimatedOutstandingIls(Customer $customer): ?string
    {
        $rate = $this->rates->suggestedRate(now()->toDateString());
        if ($rate === null) {
            return null;
        }

        return Money::convertUsdToIls($this->netBalanceUsd($customer), $rate);
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(Customer $customer): array
    {
        return [
            'total_invoiced_usd' => $this->totalInvoicedUsd($customer),
            'outstanding_usd' => $this->outstandingUsd($customer),
            'unallocated_credit_usd' => $this->unallocatedCreditUsd($customer),
            'net_balance_usd' => $this->netBalanceUsd($customer),
            'total_payments_usd' => $this->totalPaymentsUsd($customer),
            'estimated_outstanding_ils' => $this->estimatedOutstandingIls($customer),
        ];
    }
}
