<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionBillingService;
use App\Services\SubscriptionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * DEMO subscription data: active monthly/quarterly/yearly contracts, a paused
 * one, and one nearing expiry — plus recurring-invoice history generated through
 * the real billing service (so GL stays balanced and AR reconciles). No real
 * WhatsApp messages are sent (the channel is disabled; provider is Null).
 */
class SubscriptionDemoSeeder extends Seeder
{
    public function run(): void
    {
        Auth::login(User::where('email', 'accountant@superapple.ps')->first() ?? User::first());

        $customers = Customer::active()->orderBy('id')->take(4)->get();
        if ($customers->count() < 2) {
            Auth::logout();

            return;
        }

        // Ensure a current USD→ILS rate exists so auto-issue can snapshot one.
        if (ExchangeRate::latestFor(now()->toDateString()) === null) {
            ExchangeRate::create([
                'rate_date' => now()->toDateString(), 'base_currency' => 'USD',
                'quote_currency' => 'ILS', 'rate' => '3.650000', 'source' => 'manual',
                'created_by' => Auth::id(), 'updated_by' => Auth::id(),
            ]);
        }

        $service = app(SubscriptionService::class);
        $billing = app(SubscriptionBillingService::class);

        // 1) Monthly retainer, started 3 months ago, auto-issues → history.
        $monthly = $service->create([
            'customer_id' => $customers[0]->id,
            'name' => 'باقة إدارة السوشال ميديا (شهري)',
            'billing_cycle' => 'monthly', 'billing_interval' => 1,
            'start_date' => now()->subMonthsNoOverflow(3)->toDateString(),
            'auto_generate_invoice' => true, 'auto_issue_invoice' => true,
            'terms' => 'اشتراك شهري يجدد تلقائياً.',
        ], [
            ['item_name' => 'إدارة المنصات', 'quantity' => 1, 'unit_price_usd' => '400', 'tax_rate' => 0],
            ['item_name' => 'تصميم 8 منشورات', 'quantity' => 1, 'unit_price_usd' => '200', 'tax_rate' => 0],
        ]);
        $service->activate($monthly->fresh());
        $this->catchUp($billing, $monthly->fresh());

        // 2) Quarterly hosting + support, active, auto-generate (draft) only.
        $quarterly = $service->create([
            'customer_id' => $customers[1]->id,
            'name' => 'استضافة ودعم (ربع سنوي)',
            'billing_cycle' => 'quarterly', 'billing_interval' => 1,
            'start_date' => now()->subMonthsNoOverflow(1)->toDateString(),
            'auto_generate_invoice' => true, 'auto_issue_invoice' => false,
        ], [
            ['item_name' => 'استضافة سحابية', 'quantity' => 1, 'unit_price_usd' => '300', 'tax_rate' => 0],
            ['item_name' => 'دعم فني', 'quantity' => 1, 'unit_price_usd' => '150', 'tax_rate' => 0],
        ]);
        $service->activate($quarterly->fresh());
        $this->catchUp($billing, $quarterly->fresh());

        // 3) Yearly maintenance, active.
        $yearly = $service->create([
            'customer_id' => $customers[0]->id,
            'name' => 'صيانة الموقع (سنوي)',
            'billing_cycle' => 'yearly', 'billing_interval' => 1,
            'start_date' => now()->toDateString(),
            'auto_generate_invoice' => true, 'auto_issue_invoice' => true,
        ], [
            ['item_name' => 'صيانة سنوية', 'quantity' => 1, 'unit_price_usd' => '1200', 'tax_rate' => 0],
        ]);
        $service->activate($yearly->fresh());

        // 4) Paused subscription (kept with its past invoices, no future billing).
        $paused = $service->create([
            'customer_id' => $customers->count() > 2 ? $customers[2]->id : $customers[1]->id,
            'name' => 'حملة إعلانية (موقوفة مؤقتاً)',
            'billing_cycle' => 'monthly', 'billing_interval' => 1,
            'start_date' => now()->subMonthsNoOverflow(2)->toDateString(),
            'auto_generate_invoice' => true, 'auto_issue_invoice' => true,
        ], [
            ['item_name' => 'إدارة حملات', 'quantity' => 1, 'unit_price_usd' => '350', 'tax_rate' => 0],
        ]);
        $service->activate($paused->fresh());
        $this->catchUp($billing, $paused->fresh());
        $service->pause($paused->fresh());

        // 5) Expiring soon (ends in ~10 days).
        $expiring = $service->create([
            'customer_id' => $customers[1]->id,
            'name' => 'اشتراك تجريبي (ينتهي قريباً)',
            'billing_cycle' => 'monthly', 'billing_interval' => 1,
            'start_date' => now()->subMonthsNoOverflow(1)->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
            'auto_generate_invoice' => true, 'auto_issue_invoice' => false,
        ], [
            ['item_name' => 'باقة تجريبية', 'quantity' => 1, 'unit_price_usd' => '100', 'tax_rate' => 0],
        ]);
        $service->activate($expiring->fresh());

        Auth::logout();
    }

    /** Generate every past-due period up to today (builds invoice history). */
    private function catchUp(SubscriptionBillingService $billing, Subscription $subscription): void
    {
        $guard = 0;
        while ($guard++ < 24) {
            $sub = $subscription->fresh();
            if (! $sub || ! $sub->isActive() || $sub->next_billing_date === null) {
                break;
            }
            if (Carbon::parse($sub->next_billing_date)->gt(now()->endOfDay())) {
                break;
            }
            $result = $billing->billOne($sub->id, now()->toDateString());
            if (in_array($result['outcome'], ['skipped', 'failed'], true)) {
                break;
            }
        }
    }
}
