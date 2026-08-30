<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
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
     * Retired: there is no central/latest rate anymore, so no blind-rate estimate
     * is produced. The accounting-correct per-document figure is
     * {@see outstandingIlsByDocument()}; the official balance is always USD.
     * Kept returning null for backward-compatible call sites (never queries).
     */
    public function estimatedOutstandingIls(Customer $customer): ?string
    {
        return null;
    }

    /**
     * Accounting-correct ILS equivalent of the outstanding balance: the sum of
     * each open invoice's remaining × ITS OWN locked rate — never the total USD
     * times one blind rate (invoices may carry different issue rates). Display
     * estimate only; the official balance is still USD.
     */
    public function outstandingIlsByDocument(Customer $customer): string
    {
        $rows = $customer->invoices()
            ->where('status', '!=', InvoiceStatus::Cancelled->value)
            ->where('status', '!=', InvoiceStatus::Draft->value)
            ->where('remaining_usd', '>', 0)
            ->get(['remaining_usd', 'exchange_rate']);

        $total = '0.00';
        foreach ($rows as $row) {
            if ($row->exchange_rate !== null && Money::isPositive($row->exchange_rate)) {
                $total = Money::add($total, Money::convertUsdToIls($row->remaining_usd, $row->exchange_rate));
            }
        }

        return $total;
    }

    /**
     * Batch outstanding figures (USD + per-document ILS) for many customers in a
     * SINGLE query, so a list page never runs a query per row (no N+1). Mirrors
     * {@see outstandingUsd()} and {@see outstandingIlsByDocument()} exactly: the
     * `issued()` scope (not draft, not cancelled), USD summed over all such
     * invoices, ILS summed per invoice at its OWN locked rate (remaining > 0).
     *
     * @param  list<int>  $customerIds
     * @return array<int,array{usd:string,ils:string}>
     */
    public function outstandingMapForList(array $customerIds): array
    {
        $map = [];
        foreach ($customerIds as $id) {
            $map[(int) $id] = ['usd' => '0.00', 'ils' => '0.00'];
        }
        if ($customerIds === []) {
            return $map;
        }

        Invoice::issued()
            ->whereIn('customer_id', $customerIds)
            ->select(['customer_id', 'remaining_usd', 'exchange_rate'])
            ->chunk(1000, function ($rows) use (&$map) {
                foreach ($rows as $row) {
                    $cid = (int) $row->customer_id;
                    $map[$cid]['usd'] = Money::add($map[$cid]['usd'], $row->remaining_usd);
                    if ((float) $row->remaining_usd > 0 && $row->exchange_rate !== null && Money::isPositive($row->exchange_rate)) {
                        $map[$cid]['ils'] = Money::add($map[$cid]['ils'], Money::convertUsdToIls($row->remaining_usd, $row->exchange_rate));
                    }
                }
            });

        foreach ($map as $cid => $v) {
            $map[$cid]['usd'] = Money::money($v['usd']);
        }

        return $map;
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
            'outstanding_ils_by_document' => $this->outstandingIlsByDocument($customer),
        ];
    }
}
