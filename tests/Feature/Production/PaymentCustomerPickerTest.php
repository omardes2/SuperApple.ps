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
 * The payment page picks the customer through a searchable combobox (name /
 * number / whatsapp) instead of one long select. Only a draft may change its
 * customer, and switching customers clears any allocation rows.
 */
class PaymentCustomerPickerTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    private function draft(): Payment
    {
        $customer = $this->makeCustomer(); // seed at least one customer
        $draft = app(PaymentService::class)->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'USD', 'payment_amount' => 0,
        ]);

        return $draft;
    }

    private function comp(Payment $payment)
    {
        return Livewire::actingAs($this->makeUser(RoleName::GeneralManager))
            ->test(PaymentShow::class, ['payment' => $payment]);
    }

    public function test_search_field_is_shown_when_no_customer_selected(): void
    {
        $payment = $this->draft();
        $this->comp($payment)
            ->set('customer_id', null)
            ->assertSee('ابحث بالاسم / رقم العميل / واتساب...');
    }

    public function test_search_matches_by_name(): void
    {
        $payment = $this->draft();
        $target = $this->makeCustomer(['name' => 'أزياء الملكة', 'customer_number' => 'CUS-70001']);

        $results = $this->comp($payment)
            ->set('customer_id', null)
            ->set('customerSearch', 'الملكة')
            ->viewData('customerResults');

        $this->assertTrue($results->contains('id', $target->id));
    }

    public function test_search_matches_by_number_and_whatsapp(): void
    {
        $payment = $this->draft();
        $target = $this->makeCustomer(['name' => 'براء', 'customer_number' => 'CUS-70055', 'whatsapp_number' => '970599123456']);

        $byNumber = $this->comp($payment)->set('customer_id', null)
            ->set('customerSearch', '70055')->viewData('customerResults');
        $this->assertTrue($byNumber->contains('id', $target->id));

        $byWhats = $this->comp($payment)->set('customer_id', null)
            ->set('customerSearch', '599123456')->viewData('customerResults');
        $this->assertTrue($byWhats->contains('id', $target->id));
    }

    public function test_results_are_capped_at_ten(): void
    {
        $payment = $this->draft();
        for ($i = 0; $i < 15; $i++) {
            $this->makeCustomer(['name' => "عميل بحث {$i}"]);
        }

        $results = $this->comp($payment)->set('customer_id', null)
            ->set('customerSearch', 'عميل بحث')->viewData('customerResults');

        $this->assertLessThanOrEqual(10, $results->count());
    }

    public function test_selecting_a_customer_sets_it_and_clears_search(): void
    {
        $payment = $this->draft();
        $target = $this->makeCustomer(['name' => 'عميل مختار']);

        $this->comp($payment)
            ->set('customer_id', null)
            ->set('customerSearch', 'مختار')
            ->call('selectCustomer', $target->id)
            ->assertSet('customer_id', $target->id)
            ->assertSet('customerSearch', '')
            ->assertSee('عميل مختار');
    }

    public function test_changing_customer_clears_allocation_rows(): void
    {
        $payment = $this->draft();
        $other = $this->makeCustomer(['name' => 'عميل بديل']);

        $this->comp($payment)
            ->set('allocations', [['invoice_id' => 999, 'opening_balance_id' => null, 'allocated_usd' => '5.00']])
            ->call('selectCustomer', $other->id)
            ->assertSet('customer_id', $other->id)
            ->assertSet('allocations', []);
    }

    public function test_clear_customer_resets_selection(): void
    {
        $payment = $this->draft();

        $this->comp($payment)
            ->call('clearCustomer')
            ->assertSet('customer_id', null)
            ->assertSee('ابحث بالاسم / رقم العميل / واتساب...');
    }

    public function test_posted_payment_customer_is_read_only(): void
    {
        $customer = $this->makeCustomer(['name' => 'عميل مدفوع']);
        $invoice = $this->makeIssuedInvoice($customer, '100.00', '3.20');
        $cash = $this->makeCashAccount('ILS');
        $service = app(PaymentService::class);
        $payment = $service->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => '320.00', 'exchange_rate' => '3.20', 'account_id' => $cash->id,
        ]);
        $service->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => '100.00']]);

        $other = $this->makeCustomer(['name' => 'محاولة تغيير']);

        // A posted payment must not allow switching customer.
        $this->comp($payment->fresh())
            ->call('selectCustomer', $other->id)
            ->assertSet('customer_id', $customer->id)
            ->assertDontSee('ابحث بالاسم / رقم العميل / واتساب...');
    }
}
