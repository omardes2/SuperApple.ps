<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Support\Money;

/**
 * Builds a chronological USD ledger for a customer (invoices debit, payments
 * credit) with a running balance. USD is the official statement currency. A
 * multi-invoice payment appears once (as its usd_equivalent), so it is never
 * double-counted. Cancelled invoices/payments are excluded.
 */
class CustomerStatementService
{
    /**
     * @return array{entries: list<array<string,mixed>>, closing_balance_usd: string}
     */
    public function build(Customer $customer): array
    {
        $rows = [];

        foreach ($customer->invoices()
            ->where('status', '!=', InvoiceStatus::Cancelled->value)
            ->where('status', '!=', InvoiceStatus::Draft->value)
            ->get() as $invoice) {
            $rows[] = [
                'date' => $invoice->invoice_date,
                'sort' => $invoice->invoice_date->format('Y-m-d').'-1-'.$invoice->id,
                'type' => 'invoice',
                'reference' => $invoice->invoice_number,
                'description' => 'فاتورة',
                'debit_usd' => Money::money($invoice->total_usd),
                'credit_usd' => '0.00',
            ];
        }

        foreach ($customer->payments()->posted()->get() as $payment) {
            $rows[] = [
                'date' => $payment->payment_date,
                'sort' => $payment->payment_date->format('Y-m-d').'-2-'.$payment->id,
                'type' => 'payment',
                'reference' => $payment->payment_number,
                'description' => 'دفعة ('.$payment->payment_currency->value.')',
                'debit_usd' => '0.00',
                'credit_usd' => Money::money($payment->usd_equivalent),
            ];
        }

        usort($rows, fn ($a, $b) => strcmp($a['sort'], $b['sort']));

        $balance = '0.00';
        $entries = [];
        foreach ($rows as $row) {
            $balance = Money::add(Money::subtract($balance, $row['credit_usd']), $row['debit_usd']);
            unset($row['sort']);
            $row['balance_usd'] = $balance;
            $entries[] = $row;
        }

        return ['entries' => $entries, 'closing_balance_usd' => $balance];
    }
}
