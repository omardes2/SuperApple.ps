<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Models\JournalEntry;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\ReportsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The dashboard's "إيراد الشهر (محاسبي)" is Σ(credit − debit) on leaf REVENUE
 * accounts (Service Revenue + Exchange Gain) over posted+reversed journals in
 * the month — invoice issue accrues it, reversals net it out, payments/opening
 * balances never touch it. The diagnostic command must itemise and tie to that.
 */
class MonthlyRevenueDiagnosisTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    public function test_command_ties_out_to_dashboard_revenue(): void
    {
        // A September invoice issue → Service Revenue accrues.
        $this->makeIssuedInvoice($this->makeCustomer(), '100.00', '3.20');

        $revenue = app(ReportsService::class)->revenueThisMonthIls();
        $this->assertTrue((float) $revenue > 0);

        $code = Artisan::call('app:diagnose-monthly-revenue', ['--month' => now()->format('Y-m')]);
        $out = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('مطابق', $out);
        $this->assertStringContainsString($revenue, $out); // the exact number appears
    }

    public function test_reversed_invoice_nets_revenue_to_zero(): void
    {
        $svc = app(InvoiceService::class);
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '100.00', '3.20');

        // Reverting to draft reverses the issue journal → revenue nets to zero.
        $svc->revertToDraft($invoice->fresh());

        $this->assertSame('0.00', app(ReportsService::class)->revenueThisMonthIls());
        // Both the issue and its reversal exist (they offset), proving it is not
        // silently dropped.
        $this->assertSame(2, JournalEntry::where('source_type', 'invoice')
            ->where('source_id', $invoice->id)->count());
    }

    public function test_payment_collection_does_not_create_revenue(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '100.00', '3.20');
        $revenueAfterIssue = app(ReportsService::class)->revenueThisMonthIls();

        // Collect the invoice at the same rate → no FX gain, no extra revenue.
        $cash = $this->makeCashAccount('ILS');
        $payment = app(PaymentService::class)->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => '320.00', 'exchange_rate' => '3.20', 'account_id' => $cash->id,
        ]);
        app(PaymentService::class)->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => '100.00']]);

        // Revenue is unchanged — the payment credited AR/Cash, not revenue.
        $this->assertSame($revenueAfterIssue, app(ReportsService::class)->revenueThisMonthIls());
    }
}
