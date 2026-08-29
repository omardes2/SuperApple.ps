<?php

namespace Database\Seeders;

use App\Models\Invoice;
use App\Models\User;
use App\Services\PaymentService;
use App\Support\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Demo payments (Sprint 4). Exchange rates are illustrative DEMO values, not
 * real market rates. The scenarios are deliberately balanced so the numbers
 * add up: a partial ILS payment, a full USD payment, a multi-invoice payment,
 * an over-paid payment leaving customer credit, and one cancelled payment that
 * fully reverses. No real customer money is represented.
 */
class PaymentSeeder extends Seeder
{
    public function run(): void
    {
        Auth::login(User::where('email', 'accountant@superapple.ps')->first() ?? User::first());

        $service = app(PaymentService::class);

        // Open invoices grouped by customer (issued/sent/partially_paid, remaining > 0).
        $open = Invoice::whereIn('status', ['issued', 'sent', 'partially_paid'])
            ->where('remaining_usd', '>', 0)
            ->orderBy('customer_id')->orderBy('invoice_date')
            ->get()
            ->groupBy('customer_id');

        $done = ['partial' => false, 'full' => false, 'multi' => false, 'credit' => false, 'cancelled' => false];

        foreach ($open as $customerId => $invoices) {
            // ---- Full USD payment settling one invoice ----
            if (! $done['full'] && $invoices->count() >= 1) {
                $inv = $invoices->first();
                $service->post(
                    $service->createDraft([
                        'customer_id' => $customerId,
                        'payment_date' => Carbon::now()->subDays(4)->toDateString(),
                        'payment_currency' => 'USD',
                        'payment_amount' => $inv->remaining_usd,
                        'exchange_rate' => '3.31',
                        'payment_method' => 'bank_transfer',
                    ]),
                    [['invoice_id' => $inv->id, 'allocated_usd' => Money::money($inv->remaining_usd)]],
                );
                $done['full'] = true;

                continue;
            }

            // ---- Partial ILS payment (half of one invoice, independent rate) ----
            if (! $done['partial'] && $invoices->count() >= 1) {
                $inv = $invoices->first();
                $half = Money::money((float) $inv->remaining_usd / 2);
                $rate = '3.28';
                $ilsAmount = Money::convertUsdToIls($half, $rate); // pay exactly half in ILS
                $service->post(
                    $service->createDraft([
                        'customer_id' => $customerId,
                        'payment_date' => Carbon::now()->subDays(3)->toDateString(),
                        'payment_currency' => 'ILS',
                        'payment_amount' => $ilsAmount,
                        'exchange_rate' => $rate,
                        'payment_method' => 'cash',
                    ]),
                    [['invoice_id' => $inv->id, 'allocated_usd' => $half]],
                );
                $done['partial'] = true;

                continue;
            }

            // ---- Multi-invoice payment settling two invoices at once ----
            if (! $done['multi'] && $invoices->count() >= 2) {
                $a = $invoices[0];
                $b = $invoices[1];
                $total = Money::add($a->remaining_usd, $b->remaining_usd);
                $service->post(
                    $service->createDraft([
                        'customer_id' => $customerId,
                        'payment_date' => Carbon::now()->subDays(2)->toDateString(),
                        'payment_currency' => 'USD',
                        'payment_amount' => $total,
                        'exchange_rate' => '3.30',
                        'payment_method' => 'cheque',
                    ]),
                    [
                        ['invoice_id' => $a->id, 'allocated_usd' => Money::money($a->remaining_usd)],
                        ['invoice_id' => $b->id, 'allocated_usd' => Money::money($b->remaining_usd)],
                    ],
                );
                $done['multi'] = true;

                continue;
            }

            // ---- Over-paid: allocate one invoice, leave the rest as credit ----
            if (! $done['credit'] && $invoices->count() >= 1) {
                $inv = $invoices->first();
                $over = Money::add($inv->remaining_usd, '250'); // $250 becomes credit
                $service->post(
                    $service->createDraft([
                        'customer_id' => $customerId,
                        'payment_date' => Carbon::now()->subDay()->toDateString(),
                        'payment_currency' => 'USD',
                        'payment_amount' => $over,
                        'exchange_rate' => '3.29',
                        'payment_method' => 'online_payment',
                    ]),
                    [['invoice_id' => $inv->id, 'allocated_usd' => Money::money($inv->remaining_usd)]],
                );
                $done['credit'] = true;

                continue;
            }

            // ---- Cancelled payment (posted then fully reversed) ----
            if (! $done['cancelled'] && $invoices->count() >= 1) {
                $inv = $invoices->first();
                $payment = $service->createDraft([
                    'customer_id' => $customerId,
                    'payment_date' => Carbon::now()->subDays(6)->toDateString(),
                    'payment_currency' => 'USD',
                    'payment_amount' => $inv->remaining_usd,
                    'exchange_rate' => '3.30',
                    'payment_method' => 'cash',
                ]);
                $service->post($payment, [['invoice_id' => $inv->id, 'allocated_usd' => Money::money($inv->remaining_usd)]]);
                $service->cancel($payment->fresh(), Auth::user(), 'إدخال تجريبي خاطئ — عكس كامل');
                $done['cancelled'] = true;

                continue;
            }

            if (! in_array(false, $done, true)) {
                break;
            }
        }

        Auth::logout();
    }
}
