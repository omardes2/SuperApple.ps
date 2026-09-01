<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The printed invoice uses the branded Super Apple layout (header + status
 * badge, info cards, dark items table, totals with paid/remaining, contact
 * block) bound to the invoice's real fields. Presentation only.
 */
class InvoicePrintDesignTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    public function test_print_shows_branded_sections_and_real_data(): void
    {
        $customer = $this->makeCustomer(['name' => 'توفير اون لاين']);
        $invoice = $this->makeIssuedInvoice($customer, '100.00', '3.04');

        $this->get(route('admin.invoices.print', $invoice))
            ->assertOk()
            ->assertSee('فاتورة')
            ->assertSee($invoice->invoice_number)
            ->assertSee('تفاصيل الفاتورة')
            ->assertSee('معلومات العميل')
            ->assertSee('توفير اون لاين')
            ->assertSee('الوصف')          // items table header
            ->assertSee('بيانات التواصل والدفع')
            ->assertSee('المدفوع')
            ->assertSee('المتبقي')
            ->assertSee('$100.00');       // official USD figure
    }

    public function test_print_shows_paid_and_remaining_after_payment(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '100.00', '3.20');
        $cash = $this->makeCashAccount('ILS');
        $svc = app(PaymentService::class);
        $p = $svc->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => '128.00', 'exchange_rate' => '3.20', 'account_id' => $cash->id,
        ]);
        $svc->post($p, [['invoice_id' => $invoice->id, 'allocated_usd' => '40.00']]);

        $this->get(route('admin.invoices.print', $invoice->fresh()))
            ->assertOk()
            ->assertSee('$40.00')   // paid
            ->assertSee('$60.00');  // remaining
    }
}
