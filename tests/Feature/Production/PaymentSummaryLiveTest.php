<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Livewire\Admin\PaymentShow;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The draft payment summary must reflect the LIVE form values, not the last
 * saved model: an ILS payment shows its shekel amount immediately, and the USD
 * equivalent (amount ÷ rate) only appears once a rate is entered — the official
 * customer balance is USD, so an ILS payment always needs its own rate.
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

    private function draftComponent()
    {
        $customer = $this->makeCustomer();
        $payment = app(PaymentService::class)->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'USD', 'payment_amount' => 0,
        ]);

        return Livewire::actingAs($this->makeUser(RoleName::GeneralManager))
            ->test(PaymentShow::class, ['payment' => $payment]);
    }

    public function test_ils_amount_shows_in_summary_before_a_rate_is_entered(): void
    {
        $this->draftComponent()
            ->set('payment_currency', 'ILS')
            ->set('payment_amount', '220')
            ->assertSee('220.00 ₪')                 // received amount in shekels
            ->assertSee('أدخل سعر الصرف');           // USD equivalent needs a rate
    }

    public function test_usd_equivalent_appears_once_rate_is_entered(): void
    {
        $this->draftComponent()
            ->set('payment_currency', 'ILS')
            ->set('payment_amount', '527')
            ->set('exchange_rate', '3.10')
            ->assertSee('527.00 ₪')                 // received amount in shekels
            ->assertSee('$170.00')                  // 527 / 3.10
            ->assertDontSee('أدخل سعر الصرف');
    }

    public function test_usd_payment_summary_shows_dollar_amount(): void
    {
        $this->draftComponent()
            ->set('payment_currency', 'USD')
            ->set('payment_amount', '300')
            ->assertSee('300.00 $')
            ->assertSee('$300.00');
    }

    public function test_ils_rate_field_marked_required_and_explained(): void
    {
        $this->draftComponent()
            ->set('payment_currency', 'ILS')
            ->assertSee('مطلوب لتحويل مبلغ الشيكل إلى الدولار (رصيد العميل الرسمي بالدولار). يُدخل يدوياً لكل دفعة ومستقل عن سعر صرف الفاتورة.');
    }
}
