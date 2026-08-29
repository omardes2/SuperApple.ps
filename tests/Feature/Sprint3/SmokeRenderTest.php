<?php

namespace Tests\Feature\Sprint3;

use App\Enums\RoleName;
use App\Services\InvoiceService;
use App\Services\QuotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class SmokeRenderTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_billing_pages_render_for_accountant(): void
    {
        $accountant = $this->makeUser(RoleName::Accountant);
        $this->actingAs($accountant);

        $customer = $this->makeCustomer();
        $quotation = app(QuotationService::class)->createDraft(['customer_id' => $customer->id],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price_usd' => 100, 'tax_rate' => 16]]);
        $invoice = app(InvoiceService::class)->createDraft(
            ['customer_id' => $customer->id, 'exchange_rate' => '3.28'],
            [['item_name' => 'خدمة', 'quantity' => 2, 'unit_price_usd' => 1000]]);

        foreach (['/admin/exchange-rates', '/admin/quotations', '/admin/invoices'] as $url) {
            $this->get($url)->assertOk();
        }

        $this->get(route('admin.quotations.show', $quotation))->assertOk()->assertSee($quotation->quotation_number);
        $this->get(route('admin.invoices.show', $invoice))->assertOk()->assertSee($invoice->invoice_number);
    }

    public function test_print_views_render_after_issue(): void
    {
        $accountant = $this->makeUser(RoleName::Accountant);
        $this->actingAs($accountant);
        $customer = $this->makeCustomer();

        $invoice = app(InvoiceService::class)->createDraft(
            ['customer_id' => $customer->id, 'exchange_rate' => '3.28'],
            [['item_name' => 'خدمة تصميم', 'quantity' => 1, 'unit_price_usd' => 2000, 'tax_rate' => 0]]);
        app(InvoiceService::class)->issue($invoice);

        $this->get(route('admin.invoices.print', $invoice))
            ->assertOk()
            ->assertSee($invoice->invoice_number)
            ->assertSee('6,560.00')         // ILS equivalent snapshot (formatted)
            ->assertSee('USD');

        $quotation = app(QuotationService::class)->createDraft(['customer_id' => $customer->id],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price_usd' => 500]]);
        app(QuotationService::class)->send($quotation);

        $this->get(route('admin.quotations.print', $quotation))->assertOk()->assertSee($quotation->quotation_number);
    }

    public function test_customer_profile_shows_financial_tabs_for_accountant(): void
    {
        $accountant = $this->makeUser(RoleName::Accountant);
        $customer = $this->makeCustomer();

        $this->actingAs($accountant)->get(route('admin.customers.show', $customer))
            ->assertOk()
            ->assertSee('عروض الأسعار')
            ->assertSee('الفواتير');
    }
}
