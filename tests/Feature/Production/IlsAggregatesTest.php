<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Livewire\Admin\Dashboard;
use App\Services\CustomerBalanceService;
use App\Services\PaymentService;
use App\Services\ReportsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Aggregate ILS equivalents are computed per-document (each invoice/payment at
 * its own rate) — never total_usd × one latest rate. Display only; USD stays
 * the official figure.
 */
class IlsAggregatesTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    public function test_receivables_ils_sums_each_invoice_at_its_own_rate(): void
    {
        $c = $this->makeCustomer();
        $this->makeIssuedInvoice($c, '100.00', '3.00'); // 300
        $this->makeIssuedInvoice($c, '100.00', '3.20'); // 320
        // A wildly different latest rate must not be applied to the total.
        $this->seedExchangeRate(now()->toDateString(), '9.99');

        $this->assertSame('620.00', app(ReportsService::class)->receivablesIlsByDocument());
    }

    public function test_ar_aging_buckets_and_total_use_per_document_ils(): void
    {
        $c = $this->makeCustomer();
        $this->makeIssuedInvoice($c, '100.00', '3.00'); // 300
        $this->makeIssuedInvoice($c, '100.00', '3.20'); // 320

        $aging = app(ReportsService::class)->arAging();

        $this->assertSame('200.00', $aging['total']);
        $this->assertSame('620.00', $aging['total_ils']);           // 300 + 320
        $this->assertArrayHasKey('buckets_ils', $aging);
        $this->assertSame('620.00', $aging['rows'][0]['remaining_ils']); // one customer
    }

    public function test_collections_ils_uses_each_payment_rate(): void
    {
        $c = $this->makeCustomer();
        // ILS payment: contributes its own amount. USD payment: usd × its rate.
        app(PaymentService::class)->post(
            app(PaymentService::class)->createDraft([
                'customer_id' => $c->id, 'payment_currency' => 'ILS',
                'payment_amount' => '300.00', 'exchange_rate' => '3.00',
                'account_id' => $this->cashAccount('ILS')->id,
            ]), []
        );
        app(PaymentService::class)->post(
            app(PaymentService::class)->createDraft([
                'customer_id' => $c->id, 'payment_currency' => 'USD',
                'payment_amount' => '100.00', 'exchange_rate' => '3.20',
                'account_id' => $this->cashAccount('USD')->id,
            ]), []
        );

        // 300 (ILS native) + 100 × 3.20 = 620.
        $this->assertSame('620.00', app(ReportsService::class)->collectedThisMonthIls());
    }

    public function test_dashboard_finance_card_shows_per_document_ils(): void
    {
        $c = $this->makeCustomer();
        $this->makeIssuedInvoice($c, '100.00', '3.00');
        $this->makeIssuedInvoice($c, '100.00', '3.20');
        $this->seedExchangeRate(now()->toDateString(), '9.99'); // must be ignored for outstanding

        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))->test(Dashboard::class)
            ->assertViewHas('finance', fn ($f) => $f['outstanding_ils'] === '620.00');
    }

    public function test_customer_outstanding_ils_per_document(): void
    {
        $c = $this->makeCustomer();
        $this->makeIssuedInvoice($c, '100.00', '3.00');
        $this->makeIssuedInvoice($c, '100.00', '3.20');

        $this->assertSame('620.00', app(CustomerBalanceService::class)->outstandingIlsByDocument($c));
    }

    public function test_employee_cannot_see_dashboard_finance_card(): void
    {
        [$employee] = $this->makeStaff();

        Livewire::actingAs($employee)->test(Dashboard::class)
            ->assertViewHas('finance', fn ($f) => $f === null); // no finance data for employees
    }
}
