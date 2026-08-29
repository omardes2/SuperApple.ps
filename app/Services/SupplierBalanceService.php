<?php

namespace App\Services;

use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Support\Money;

/**
 * Supplier payables in ILS accounting value (the AP ledger currency). Each open
 * bill's remaining is valued at its own bill rate, matching how AP was booked.
 */
class SupplierBalanceService
{
    /** Outstanding payable (ILS accounting value) = Σ remaining × bill rate. */
    public function outstandingIls(Supplier $supplier): string
    {
        $total = '0.00';
        foreach ($supplier->bills()->openPayable()->get() as $bill) {
            $total = Money::add($total, $this->remainingIls($bill));
        }

        return $total;
    }

    public function remainingIls(SupplierBill $bill): string
    {
        $rate = $bill->currency === 'USD' && $bill->exchange_rate ? Money::rate($bill->exchange_rate) : '1';

        return $bill->currency === 'USD'
            ? Money::convertUsdToIls($bill->remaining_original, $rate)
            : Money::money($bill->remaining_original);
    }

    public function totalBilledIls(Supplier $supplier): string
    {
        $sum = $supplier->bills()
            ->where('status', '!=', 'draft')
            ->where('status', '!=', 'cancelled')
            ->sum('total_ils');

        return Money::money($sum);
    }

    public function totalPaidIls(Supplier $supplier): string
    {
        $sum = $supplier->payments()->posted()->sum('amount_ils');

        return Money::money($sum);
    }

    /**
     * @return array<string,string>
     */
    public function summary(Supplier $supplier): array
    {
        return [
            'total_billed_ils' => $this->totalBilledIls($supplier),
            'total_paid_ils' => $this->totalPaidIls($supplier),
            'outstanding_ils' => $this->outstandingIls($supplier),
        ];
    }
}
