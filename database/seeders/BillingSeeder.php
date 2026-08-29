<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use App\Services\ExchangeRateService;
use App\Services\InvoiceService;
use App\Services\QuotationService;
use App\Services\QuotationToInvoiceService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Demo billing data. Exchange rates here are illustrative DEMO values, not real
 * historical market rates. No payments are created — every issued invoice is
 * paid_usd_equivalent = 0 / remaining_usd = total_usd (payments arrive in
 * Sprint 4).
 */
class BillingSeeder extends Seeder
{
    public function run(): void
    {
        Auth::login(User::where('email', 'accountant@superapple.ps')->first() ?? User::first());

        $rates = app(ExchangeRateService::class);
        $quotations = app(QuotationService::class);
        $invoices = app(InvoiceService::class);
        $converter = app(QuotationToInvoiceService::class);

        // ---- Exchange rates (DEMO values) ----
        foreach ([
            ['-10 days', '3.28'], ['-7 days', '3.30'], ['-5 days', '3.27'],
            ['-3 days', '3.31'], ['-1 day', '3.29'], ['now', '3.30'],
        ] as [$when, $rate]) {
            $rates->set(['rate_date' => Carbon::parse($when)->toDateString(), 'rate' => $rate, 'source' => 'manual', 'notes' => 'قيمة تجريبية']);
        }

        $customers = Customer::orderBy('id')->take(8)->get();
        $services = Service::orderBy('id')->get();
        if ($customers->isEmpty() || $services->isEmpty()) {
            Auth::logout();

            return;
        }

        $lineFor = fn (Service $s, int $qty) => [
            'service_id' => $s->id,
            'quantity' => $qty,
            'unit_price_usd' => $s->default_price_usd,
            'tax_rate' => $s->tax_rate,
        ];

        // ---- ~10 quotations across states ----
        $created = [];
        for ($i = 0; $i < 10; $i++) {
            $customer = $customers[$i % $customers->count()];
            $q = $quotations->createDraft([
                'customer_id' => $customer->id,
                'quotation_date' => Carbon::now()->subDays(random_int(1, 20))->toDateString(),
                'valid_until' => Carbon::now()->addDays(14)->toDateString(),
            ], [
                $lineFor($services->random(), random_int(1, 3)),
                $lineFor($services->random(), random_int(1, 2)),
            ]);
            $created[] = $q;
        }

        // Move some through the workflow.
        foreach ($created as $index => $q) {
            $roll = $index % 5;
            if ($roll >= 1) {
                $quotations->send($q->fresh());
            }
            if ($roll === 2) {
                $quotations->accept($q->fresh());
            }
            if ($roll === 3) {
                $quotations->reject($q->fresh());
            }
        }

        // ---- Invoices ----
        // A few converted from accepted quotations.
        foreach ($created as $index => $q) {
            if ($index % 5 === 2) {
                $invoice = $converter->convert($q->fresh());
                if ($index % 2 === 0) {
                    $invoices->issue($invoice->fresh());
                }
            }
        }

        // A few standalone invoices, mix of draft / issued / overdue.
        for ($i = 0; $i < 6; $i++) {
            $customer = $customers[$i % $customers->count()];
            $issued = $i % 3 !== 0;
            $overdue = $i % 3 === 1;
            $invoiceDate = $overdue ? Carbon::now()->subDays(45)->toDateString() : Carbon::now()->subDays(random_int(0, 10))->toDateString();

            $invoice = $invoices->createDraft([
                'customer_id' => $customer->id,
                'invoice_date' => $invoiceDate,
                'exchange_rate' => '3.30',
            ], [
                $lineFor($services->random(), random_int(1, 2)),
            ]);

            if ($issued) {
                $invoices->issue($invoice->fresh());
            }
        }

        Auth::logout();
    }
}
