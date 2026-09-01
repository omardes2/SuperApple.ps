<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Livewire\Admin\PaymentShow;
use App\Models\Customer;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The draft payment summary reflects the LIVE form values. An ILS payment needs
 * no manual rate — its shekel amount shows at once and the USD equivalent is
 * derived from the rate of the invoice being settled. USD payments keep the
 * manual rate field.
 */
class PaymentSummaryLiveTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    private function draftFor(Customer $customer): Payment
    {
        return app(PaymentService::class)->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'USD', 'payment_amount' => 0,
        ]);
    }

    private function comp(Payment $payment)
    {
        return Livewire::actingAs($this->makeUser(RoleName::GeneralManager))
            ->test(PaymentShow::class, ['payment' => $payment]);
    }

    public function test_ils_amount_shows_in_summary_immediately(): void
    {
        $customer = $this->makeCustomer();
        $this->comp($this->draftFor($customer))
            ->set('payment_currency', 'ILS')
            ->set('payment_amount', '220')
            ->assertSee('220.00 ₪');
    }

    public function test_ils_rate_field_is_hidden_and_no_manual_rate_prompt(): void
    {
        $customer = $this->makeCustomer();
        $this->comp($this->draftFor($customer))
            ->set('payment_currency', 'ILS')
            ->assertSee('يُحتسب تلقائياً حسب سعر صرف الفاتورة المُخصَّصة — المبلغ بالشيكل كما هو')
            ->assertDontSee('1 USD = ? ILS');
    }

    public function test_ils_usd_equivalent_derives_from_invoice_rate(): void
    {
        $customer = $this->makeCustomer();
        $this->makeIssuedInvoice($customer, '170.00', '3.10'); // open invoice at 3.10

        // 527 ILS ÷ 3.10 = 170.00 USD, derived from the invoice's own rate.
        $this->comp($this->draftFor($customer))
            ->set('payment_currency', 'ILS')
            ->set('payment_amount', '527')
            ->assertSee('527.00 ₪')
            ->assertSee('$170.00');
    }

    public function test_ils_without_any_open_invoice_prompts_to_choose_one(): void
    {
        $customer = $this->makeCustomer(); // no open invoices
        $this->comp($this->draftFor($customer))
            ->set('payment_currency', 'ILS')
            ->set('payment_amount', '220')
            ->assertSee('اختر فاتورة لتحديد القيمة');
    }

    public function test_usd_payment_keeps_rate_field_and_shows_dollars(): void
    {
        $customer = $this->makeCustomer();
        $this->comp($this->draftFor($customer))
            ->set('payment_currency', 'USD')
            ->set('payment_amount', '300')
            ->assertSee('1 USD = ? ILS')
            ->assertSee('300.00 $')
            ->assertSee('$300.00');
    }
}
