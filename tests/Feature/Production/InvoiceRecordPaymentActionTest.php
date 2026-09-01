<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Livewire\Admin\InvoicesIndex;
use App\Models\Payment;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The invoices list has a "record payment" action next to "view": it creates a
 * draft payment for the invoice's customer and sends the user to the payment
 * page with the invoice prefilled, where posting writes the GL journals. The
 * action is offered only for invoices that still accept a payment.
 */
class InvoiceRecordPaymentActionTest extends TestCase
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
        return Livewire::actingAs($this->makeUser(RoleName::GeneralManager))
            ->test(InvoicesIndex::class);
    }

    public function test_action_shown_for_an_open_issued_invoice(): void
    {
        $this->makeIssuedInvoice($this->makeCustomer(), '100.00', '3.20');

        $this->comp()->assertSee('تسجيل دفعة عن الفاتورة');
    }

    public function test_action_hidden_for_a_draft_invoice(): void
    {
        $customer = $this->makeCustomer();
        app(InvoiceService::class)->createDraft(
            ['customer_id' => $customer->id, 'invoice_date' => '2026-08-01', 'exchange_rate' => '3.20'],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price_usd' => '100.00', 'tax_rate' => 0]],
        );

        $this->comp()->assertDontSee('تسجيل دفعة عن الفاتورة');
    }

    public function test_record_payment_creates_a_draft_for_the_invoice_customer_and_redirects(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '100.00', '3.20');

        $this->comp()
            ->call('recordPayment', $invoice->id)
            ->assertRedirect();

        $payment = Payment::latest('id')->first();
        $this->assertNotNull($payment);
        $this->assertSame($customer->id, $payment->customer_id);
        $this->assertTrue($payment->isDraft());
    }

    public function test_record_payment_targets_the_invoice_via_deep_link(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '100.00', '3.20');

        $this->comp()->call('recordPayment', $invoice->id)
            ->assertRedirect(route('admin.payments.show', [
                'payment' => Payment::latest('id')->first(),
                'invoice' => $invoice->id,
            ]));
    }

    public function test_record_payment_rejected_for_a_fully_paid_invoice(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '100.00', '3.20');
        $cash = $this->makeCashAccount('ILS');
        $service = app(PaymentService::class);
        $payment = $service->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => '320.00', 'exchange_rate' => '3.20', 'account_id' => $cash->id,
        ]);
        $service->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => '100.00']]);
        $this->assertSame('0.00', $invoice->fresh()->remaining_usd);

        // No remaining balance → the invoice no longer accepts a payment.
        $this->comp()->call('recordPayment', $invoice->id)->assertStatus(422);
    }

    public function test_user_without_payment_create_permission_sees_no_action(): void
    {
        $this->makeIssuedInvoice($this->makeCustomer(), '100.00', '3.20');

        $role = Role::findOrCreate('عارض فواتير', 'web');
        $role->syncPermissions(['invoices.view']);
        $user = $this->makeUser(RoleName::GeneralManager);
        $user->syncRoles(['عارض فواتير']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Livewire::actingAs($user->fresh())->test(InvoicesIndex::class)
            ->assertDontSee('تسجيل دفعة عن الفاتورة');
    }
}
