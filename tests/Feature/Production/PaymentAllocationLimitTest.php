<?php

namespace Tests\Feature\Production;

use App\Enums\InvoiceStatus;
use App\Enums\RoleName;
use App\Livewire\Admin\PaymentShow;
use App\Models\Customer;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Regression suite for payment-allocation limits.
 *
 * The bug: opening /admin/payments/{id}?invoice=N prefilled the allocation row
 * with the invoice's FULL remaining_usd, ignoring the payment's USD equivalent.
 * A 3.00 ILS payment (@ 3.01 = ~1.00 USD) against a 10.00 USD invoice prefilled
 * 10.00 and then failed validation with "التخصيصات تتجاوز قيمة الدفعة".
 *
 * The rule everywhere: allocation_usd = min(invoice.remaining_usd,
 * available_payment_usd), and sum(allocation_usd) never exceeds the payment's
 * USD equivalent.
 */
class PaymentAllocationLimitTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function service(): PaymentService
    {
        return app(PaymentService::class);
    }

    private function customer(): Customer
    {
        return $this->makeCustomer();
    }

    /** An ILS draft payment for a customer (amount 0 until entered in the UI). */
    private function ilsDraft(Customer $customer)
    {
        return $this->service()->createDraft([
            'customer_id' => $customer->id,
            'payment_currency' => 'ILS',
            'payment_amount' => 0,
        ]);
    }

    /** Open the payment page as if reached via the "record payment" deep link. */
    private function open($user, $payment, int $invoiceId)
    {
        return Livewire::actingAs($user)
            ->withQueryParams(['invoice' => $invoiceId])
            ->test(PaymentShow::class, ['payment' => $payment]);
    }

    // ---------------------------------------------------------------------
    // 1. The confirmed bug: deep-link prefill must not put full remaining.
    // ---------------------------------------------------------------------

    public function test_deep_link_prefill_caps_at_payment_usd_not_full_remaining(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $customer = $this->customer();
        $invoice = $this->makeIssuedInvoice($customer, '10.00', '3.20'); // remaining 10 USD
        $payment = $this->ilsDraft($customer);

        $component = $this->open($gm, $payment, $invoice->id)
            // Fresh draft: amount not entered yet → allocation starts at 0, NOT 10.
            ->assertSet('allocations.0.invoice_id', $invoice->id)
            ->assertSet('allocations.0.allocated_usd', '0.00')
            // Enter 3.00 ILS @ 3.01 → USD equivalent ~1.00 → allocation becomes 1.00.
            ->set('payment_amount', '3.00')
            ->set('exchange_rate', '3.01');

        $component->assertSet('allocations.0.allocated_usd', '1.00');

        // Posting allocates exactly 1.00 and leaves 9.00 remaining (PartiallyPaid).
        $component->call('post')->assertHasNoErrors();

        $invoice->refresh();
        $this->assertSame('1.00', $invoice->paid_usd_equivalent);
        $this->assertSame('9.00', $invoice->remaining_usd);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);
    }

    // ---------------------------------------------------------------------
    // 2/3. Full and partial settlement through the component.
    // ---------------------------------------------------------------------

    public function test_full_payment_settles_invoice(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $customer = $this->customer();
        $invoice = $this->makeIssuedInvoice($customer, '10.00', '3.20');
        $payment = $this->ilsDraft($customer);

        $this->open($gm, $payment, $invoice->id)
            ->set('payment_amount', '30.80')  // 30.80 / 3.08 = 10.00 USD
            ->set('exchange_rate', '3.08')
            ->assertSet('allocations.0.allocated_usd', '10.00')
            ->call('post')->assertHasNoErrors();

        $invoice->refresh();
        $this->assertSame('0.00', $invoice->remaining_usd);
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
    }

    public function test_partial_payment_leaves_remaining(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $customer = $this->customer();
        $invoice = $this->makeIssuedInvoice($customer, '10.00', '3.20');
        $payment = $this->ilsDraft($customer);

        $this->open($gm, $payment, $invoice->id)
            ->set('payment_amount', '15.40')  // 15.40 / 3.08 = 5.00 USD
            ->set('exchange_rate', '3.08')
            ->assertSet('allocations.0.allocated_usd', '5.00')
            ->call('post')->assertHasNoErrors();

        $invoice->refresh();
        $this->assertSame('5.00', $invoice->remaining_usd);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);
    }

    // ---------------------------------------------------------------------
    // 4/5. Recalculation when amount / rate changes (auto mode).
    // ---------------------------------------------------------------------

    public function test_lowering_amount_recalculates_auto_allocation(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $customer = $this->customer();
        $invoice = $this->makeIssuedInvoice($customer, '10.00', '3.20');
        $payment = $this->ilsDraft($customer);

        $this->open($gm, $payment, $invoice->id)
            ->set('payment_amount', '30.80')  // 10.00 USD @ 3.08
            ->set('exchange_rate', '3.08')
            ->assertSet('allocations.0.allocated_usd', '10.00')
            // Drop the amount: allocation must shrink, never stay at 10.
            ->set('payment_amount', '3.00')   // 3.00 / 3.08 = 0.97 USD
            ->assertSet('allocations.0.allocated_usd', '0.97');
    }

    public function test_changing_exchange_rate_recalculates_available_usd(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $customer = $this->customer();
        $invoice = $this->makeIssuedInvoice($customer, '10.00', '3.20');
        $payment = $this->ilsDraft($customer);

        $this->open($gm, $payment, $invoice->id)
            ->set('payment_amount', '30.80')
            ->set('exchange_rate', '3.08')          // 10.00 USD
            ->assertSet('allocations.0.allocated_usd', '10.00')
            ->set('exchange_rate', '6.16')          // 30.80 / 6.16 = 5.00 USD
            ->assertSet('allocations.0.allocated_usd', '5.00');
    }

    public function test_manual_edit_stops_auto_recalculation(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $customer = $this->customer();
        $invoice = $this->makeIssuedInvoice($customer, '10.00', '3.20');
        $payment = $this->ilsDraft($customer);

        $this->open($gm, $payment, $invoice->id)
            ->set('payment_amount', '30.80')
            ->set('exchange_rate', '3.08')
            ->assertSet('allocations.0.allocated_usd', '10.00')
            // Accountant types their own number → auto mode off.
            ->set('allocations.0.allocated_usd', '4.00')
            ->assertSet('autoMode', false)
            // A later amount change must NOT overwrite the manual value.
            ->set('payment_amount', '61.60')
            ->assertSet('allocations.0.allocated_usd', '4.00');
    }

    // ---------------------------------------------------------------------
    // 6/7. Auto-allocate button distributes only what is available.
    // ---------------------------------------------------------------------

    public function test_auto_allocate_button_cannot_exceed_payment_equivalent(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $customer = $this->customer();
        $a = $this->makeIssuedInvoice($customer, '10.00', '3.20');
        $b = $this->makeIssuedInvoice($customer, '20.00', '3.20');
        $payment = $this->ilsDraft($customer);

        // 36.96 / 3.08 = 12.00 USD available across A(10) + B(20).
        $component = Livewire::actingAs($gm)->test(PaymentShow::class, ['payment' => $payment])
            ->set('payment_amount', '36.96')
            ->set('exchange_rate', '3.08')
            ->call('autoAllocate');

        $allocations = collect($component->get('allocations'))->keyBy('invoice_id');
        $this->assertSame('10.00', $allocations[$a->id]['allocated_usd']);
        $this->assertSame('2.00', $allocations[$b->id]['allocated_usd']); // only the remaining 2, not 20
    }

    // ---------------------------------------------------------------------
    // 8/9/10. Backend guards hold even against crafted input.
    // ---------------------------------------------------------------------

    public function test_backend_rejects_allocation_over_payment_equivalent(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $customer = $this->customer();
        $invoice = $this->makeIssuedInvoice($customer, '10.00', '3.20');
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => '3.00', 'exchange_rate' => '3.01',
        ]); // ~1.00 USD

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('مجموع التخصيصات يتجاوز قيمة الدفعة');
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => '10.00']]);
    }

    public function test_backend_rejects_allocation_over_invoice_remaining(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $customer = $this->customer();
        $invoice = $this->makeIssuedInvoice($customer, '5.00', '3.20');
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'USD',
            'payment_amount' => '100.00', 'exchange_rate' => '3.30',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('تتجاوز المتبقي على الفاتورة');
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => '10.00']]);
    }

    public function test_backend_rejects_allocation_to_another_customers_invoice(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $customerA = $this->customer();
        $customerB = $this->customer();
        $invoiceB = $this->makeIssuedInvoice($customerB, '100.00', '3.20');
        $payment = $this->service()->createDraft([
            'customer_id' => $customerA->id, 'payment_currency' => 'USD',
            'payment_amount' => '50.00', 'exchange_rate' => '3.30',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('عميل آخر');
        $this->service()->post($payment, [['invoice_id' => $invoiceB->id, 'allocated_usd' => '50.00']]);
    }

    // ---------------------------------------------------------------------
    // 11/12. USD payment (no FX conversion) and cancellation reversal.
    // ---------------------------------------------------------------------

    public function test_usd_payment_allocates_without_conversion(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $customer = $this->customer();
        $invoice = $this->makeIssuedInvoice($customer, '10.00', '3.20');
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'USD', 'payment_amount' => 0,
        ]);

        $this->open($gm, $payment, $invoice->id)
            ->set('payment_amount', '4.00')   // USD: equivalent = 4.00 exactly
            ->set('exchange_rate', '3.50')     // payment-date rate (for GL only)
            ->assertSet('allocations.0.allocated_usd', '4.00')
            ->call('post')->assertHasNoErrors();

        $invoice->refresh();
        $this->assertSame('6.00', $invoice->remaining_usd);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);
    }

    public function test_cancellation_reverses_allocation_and_restores_invoice(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $customer = $this->customer();
        $invoice = $this->makeIssuedInvoice($customer, '10.00', '3.20');
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'USD',
            'payment_amount' => '10.00', 'exchange_rate' => '3.30',
        ]);
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => '10.00']]);
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);

        $this->service()->cancel($payment->fresh(), $gm, 'خطأ إدخال');

        $invoice->refresh();
        $this->assertSame('10.00', $invoice->remaining_usd);
        $this->assertSame('0.00', $invoice->paid_usd_equivalent);
        $this->assertContains($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::Sent]);
    }
}
