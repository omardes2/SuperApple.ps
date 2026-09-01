<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Livewire\Admin\PaymentCreate;
use App\Livewire\Admin\PaymentsIndex;
use App\Models\CustomerOpeningBalance;
use App\Models\Payment;
use App\Services\CustomerOpeningBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The new payment flow persists NOTHING until the user posts (or explicitly
 * saves a draft): "+ دفعة" opens a create page, and the customer's open
 * receivables (opening balance + invoices) are shown at the top so a payment
 * can be applied to one in a click. All posting goes through PaymentService.
 */
class PaymentCreateFlowTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    private function comp()
    {
        return Livewire::actingAs($this->makeUser(RoleName::GeneralManager))->test(PaymentCreate::class);
    }

    public function test_index_create_does_not_persist_a_draft(): void
    {
        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))->test(PaymentsIndex::class)
            ->call('create')
            ->assertRedirect(route('admin.payments.create'));

        $this->assertSame(0, Payment::count()); // no draft created
    }

    public function test_page_renders(): void
    {
        $this->comp()->assertOk()->assertSee('تسجيل دفعة')->assertSee('ابحث بالاسم');
    }

    public function test_selecting_customer_shows_receivables_at_top(): void
    {
        $customer = $this->makeCustomer(['name' => 'عميل ذمم']);
        $invoice = $this->makeIssuedInvoice($customer, '170.00', '3.04');

        $this->comp()
            ->call('selectCustomer', $customer->id)
            ->assertSee('ذمم العميل')
            ->assertSee($invoice->invoice_number)
            ->assertSee('$170.00');
    }

    public function test_pay_invoice_fills_amount_and_allocation(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '170.00', '3.04');

        $comp = $this->comp()
            ->call('selectCustomer', $customer->id)
            ->set('payment_currency', 'ILS')
            ->call('payInvoice', $invoice->id);

        // ILS amount auto-filled at the invoice rate (170 × 3.04 = 516.80).
        $comp->assertSet('payment_amount', '516.80');
        $allocs = $comp->get('allocations');
        $this->assertCount(1, $allocs);
        $this->assertSame($invoice->id, $allocs[0]['invoice_id']);
        $this->assertSame('170.00', $allocs[0]['allocated_usd']);
    }

    public function test_pay_invoice_toggles_off(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '100.00', '3.20');

        $comp = $this->comp()->call('selectCustomer', $customer->id)
            ->call('payInvoice', $invoice->id)
            ->call('payInvoice', $invoice->id); // toggle off

        $this->assertCount(0, $comp->get('allocations'));
    }

    public function test_post_creates_and_posts_in_one_step(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '100.00', '3.20');
        $cash = $this->makeCashAccount('ILS');

        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))->test(PaymentCreate::class)
            ->call('selectCustomer', $customer->id)
            ->set('payment_currency', 'ILS')
            ->call('payInvoice', $invoice->id)   // 320.00 ₪, allocate $100
            ->set('account_id', $cash->id)
            ->call('post')
            ->assertRedirect();

        $payment = Payment::latest('id')->first();
        $this->assertNotNull($payment);
        $this->assertTrue($payment->isPosted());
        $this->assertSame('0.00', $invoice->fresh()->remaining_usd); // settled
    }

    public function test_failed_post_persists_nothing(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '100.00', '3.20');
        // No deposit account chosen → post validation fails.

        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))->test(PaymentCreate::class)
            ->call('selectCustomer', $customer->id)
            ->set('payment_currency', 'ILS')
            ->call('payInvoice', $invoice->id)
            ->call('post')
            ->assertHasErrors('account_id');

        $this->assertSame(0, Payment::count());                 // nothing persisted
        $this->assertSame('100.00', $invoice->fresh()->remaining_usd); // invoice untouched
    }

    public function test_pay_opening_balance(): void
    {
        $customer = $this->makeCustomer();
        $ob = app(CustomerOpeningBalanceService::class)->create($customer, [
            'type' => 'debit', 'amount_usd' => '50.00', 'exchange_rate' => '3.00', 'balance_date' => '2026-01-01',
        ]);
        $cash = $this->makeCashAccount('ILS');

        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))->test(PaymentCreate::class)
            ->call('selectCustomer', $customer->id)
            ->set('payment_currency', 'ILS')
            ->call('payOpeningBalance', $ob->id) // 150.00 ₪ (50 × 3.00)
            ->set('account_id', $cash->id)
            ->call('post')
            ->assertRedirect();

        $this->assertSame('0.00', CustomerOpeningBalance::find($ob->id)->remaining_usd);
    }

    public function test_two_invoices_with_different_rates_settle_in_one_ils_payment(): void
    {
        // Regression: $1 @ 3.04 (3.04₪) + $2 @ 3.00 (6.00₪) = 9.04₪ for $3.00.
        // A single-rate ILS payment must not reject this as over-allocated; it
        // posts at a blended rate so received/AR/cash tie out.
        $customer = $this->makeCustomer();
        $inv1 = $this->makeIssuedInvoice($customer, '1.00', '3.04');
        $inv2 = $this->makeIssuedInvoice($customer, '2.00', '3.00');
        $cash = $this->makeCashAccount('ILS');

        $comp = Livewire::actingAs($this->makeUser(RoleName::GeneralManager))->test(PaymentCreate::class)
            ->call('selectCustomer', $customer->id)
            ->set('payment_currency', 'ILS')
            ->call('payInvoice', $inv1->id)
            ->call('payInvoice', $inv2->id);

        // Received = 9.04 ₪ and no over-allocation surplus.
        $comp->assertSet('payment_amount', '9.04');
        $ps = $comp->viewData('paymentSummary');
        $this->assertSame('exact', $ps['state']);
        $this->assertSame('0.00', $ps['surplus_usd']);

        $comp->set('account_id', $cash->id)->call('post')->assertHasNoErrors()->assertRedirect();

        $this->assertSame('0.00', $inv1->fresh()->remaining_usd);
        $this->assertSame('0.00', $inv2->fresh()->remaining_usd);
        $this->assertSame(0, Artisan::call('app:verify-integrity'));
    }

    public function test_save_draft_persists_a_draft(): void
    {
        $customer = $this->makeCustomer();

        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))->test(PaymentCreate::class)
            ->call('selectCustomer', $customer->id)
            ->set('payment_currency', 'USD')
            ->set('payment_amount', '50.00')
            ->call('save')
            ->assertRedirect();

        $payment = Payment::latest('id')->first();
        $this->assertNotNull($payment);
        $this->assertTrue($payment->isDraft());
    }
}
