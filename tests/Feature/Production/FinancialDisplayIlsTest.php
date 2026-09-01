<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Livewire\Admin\CustomerProfile;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\InvoicesIndex;
use App\Livewire\Admin\PaymentsIndex;
use App\Models\Customer;
use App\Services\PaymentService;
use App\Services\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Presentation standard: ILS is the PRIMARY displayed figure across the admin
 * UI; USD is a secondary reference tied to invoice/receivable documents. Every
 * ILS figure is derived from the record's OWN stored rate/accounting value —
 * never a current/global rate — so no accounting result changes.
 */
class FinancialDisplayIlsTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    private function user()
    {
        return $this->makeUser(RoleName::GeneralManager);
    }

    /** Settle an invoice with an ILS payment; returns [invoice, payment]. */
    private function issuedAndPaidIls(string $usd, string $rate, string $allocate): array
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, $usd, $rate);
        $cash = $this->makeCashAccount('ILS');
        $svc = app(PaymentService::class);
        $p = $svc->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => sprintf('%.2f', (float) $allocate * (float) $rate),
            'exchange_rate' => $rate, 'account_id' => $cash->id,
        ]);
        $svc->post($p, [['invoice_id' => $invoice->id, 'allocated_usd' => $allocate]]);

        return [$invoice->fresh(), $p->fresh()];
    }

    // ---- Dashboard ----

    public function test_dashboard_collections_and_receivables_are_ils_primary(): void
    {
        $this->issuedAndPaidIls('100.00', '3.20', '100.00'); // fully paid → collected 320 ₪

        Livewire::actingAs($this->user())->test(Dashboard::class)
            ->assertSee('التحصيل هذا الشهر')   // relabelled
            ->assertDontSee('محصّل هذا الشهر')
            ->assertSee('320.00 ₪')            // collected this month, ILS primary
            ->assertSee('التحصيل الشهري (₪)')   // chart title in ILS
            ->assertDontSee('USD equivalent');
    }

    public function test_dashboard_revenue_is_ils(): void
    {
        // $100 @ 3.20 issued this month → GL revenue 320.00 ₪.
        $this->makeIssuedInvoice($this->makeCustomer(), '100.00', '3.20');

        Livewire::actingAs($this->user())->test(Dashboard::class)
            ->assertSee('إيراد الشهر (محاسبي)')
            ->assertSee('320.00 ₪');
    }

    // ---- Invoices index ----

    public function test_invoice_index_totals_are_ils_primary_with_usd_secondary(): void
    {
        $this->makeIssuedInvoice($this->makeCustomer(), '170.00', '3.04'); // 516.80 ₪

        Livewire::actingAs($this->user())->test(InvoicesIndex::class)
            ->assertSee('516.80 ₪')   // total, ILS primary (stored rate)
            ->assertSee('$170.00');   // official USD secondary
    }

    // ---- Payments index ----

    public function test_ils_payment_displays_actual_ils(): void
    {
        $this->issuedAndPaidIls('100.00', '3.20', '100.00'); // 320 ₪ received

        Livewire::actingAs($this->user())->test(PaymentsIndex::class)
            ->assertSee('320.00 ₪')   // actual shekels received (primary)
            ->assertSee('$100.00');   // USD equivalent (secondary)
    }

    public function test_usd_payment_displays_accounting_ils_valuation(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '100.00', '3.20');
        $cash = $this->makeCashAccount('USD');
        $svc = app(PaymentService::class);
        $p = $svc->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'USD',
            'payment_amount' => '100.00', 'exchange_rate' => '3.20', 'account_id' => $cash->id,
        ]);
        $svc->post($p, [['invoice_id' => $invoice->id, 'allocated_usd' => '100.00']]);

        // ILS valuation = 100 × 3.20 = 320.00 ₪ (the payment's own stored rate).
        Livewire::actingAs($this->user())->test(PaymentsIndex::class)
            ->assertSee('320.00 ₪')
            ->assertSee('$100.00');
    }

    // ---- Customer profile ----

    public function test_customer_outstanding_is_ils_primary(): void
    {
        $customer = $this->makeCustomer();
        $this->makeIssuedInvoice($customer, '170.00', '3.04'); // outstanding 516.80 ₪

        Livewire::actingAs($this->user())->test(CustomerProfile::class, ['customer' => $customer])
            ->assertSee('516.80 ₪')
            ->assertSee('$170.00');
    }

    // ---- Accounting untouched ----

    public function test_reconciliation_stays_balanced_after_display_changes(): void
    {
        $this->issuedAndPaidIls('100.00', '3.20', '40.00'); // partial

        $recon = app(ReconciliationService::class);
        $this->assertTrue($recon->accountsReceivable()['balanced']);
        $this->assertTrue($recon->cash()['balanced']);
        $this->assertSame(0, Artisan::call('app:verify-integrity'));
    }
}
