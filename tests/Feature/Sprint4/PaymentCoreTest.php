<?php

namespace Tests\Feature\Sprint4;

use App\Enums\InvoiceStatus;
use App\Enums\RoleName;
use App\Exceptions\PostedPaymentImmutableException;
use App\Models\Customer;
use App\Models\PaymentAllocation;
use App\Services\CustomerBalanceService;
use App\Services\ExchangeRateService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class PaymentCoreTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    private function service(): PaymentService
    {
        return app(PaymentService::class);
    }

    private function customer(): Customer
    {
        return $this->makeCustomer();
    }

    // ---- USD payments ----

    public function test_usd_payment_equivalent_equals_amount(): void
    {
        $customer = $this->customer();
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'USD',
            'payment_amount' => 500, 'exchange_rate' => '3.30',
        ]);

        $this->assertSame('500.00', $payment->usd_equivalent);
    }

    // ---- ILS payments ----

    public function test_ils_payment_converts_by_division(): void
    {
        $customer = $this->customer();
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => 3300, 'exchange_rate' => '3.30',
        ]);

        // 3,300 / 3.30 = $1,000.
        $this->assertSame('1000.00', $payment->usd_equivalent);
    }

    public function test_posting_requires_positive_rate(): void
    {
        $customer = $this->customer();
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => 3300, 'exchange_rate' => null,
        ]);

        $this->expectException(RuntimeException::class);
        $this->service()->post($payment, []);
    }

    public function test_payment_rate_locked_after_posting(): void
    {
        $customer = $this->customer();
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => 3300, 'exchange_rate' => '3.30',
        ]);
        $this->service()->post($payment, []);

        $this->expectException(PostedPaymentImmutableException::class);
        $payment->fresh()->update(['exchange_rate' => '3.99']);
    }

    // ---- Allocation ----

    public function test_partial_allocation_sets_partially_paid(): void
    {
        $customer = $this->customer();
        $invoice = $this->makeIssuedInvoice($customer, '3000', '3.20');

        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => 3300, 'exchange_rate' => '3.30',
        ]);
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => 1000]]);

        $invoice->refresh();
        $this->assertSame('1000.00', $invoice->paid_usd_equivalent);
        $this->assertSame('2000.00', $invoice->remaining_usd);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);
    }

    public function test_full_allocation_sets_paid(): void
    {
        $customer = $this->customer();
        $invoice = $this->makeIssuedInvoice($customer, '1000', '3.20');

        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'USD',
            'payment_amount' => 1000, 'exchange_rate' => '3.30',
        ]);
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => 1000]]);

        $invoice->refresh();
        $this->assertSame('0.00', $invoice->remaining_usd);
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
    }

    public function test_over_allocation_to_invoice_is_rejected(): void
    {
        $customer = $this->customer();
        $invoice = $this->makeIssuedInvoice($customer, '500', '3.20');
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'USD', 'payment_amount' => 1000, 'exchange_rate' => '3.30',
        ]);

        $this->expectException(RuntimeException::class);
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => 700]]);
    }

    public function test_payment_over_allocation_is_rejected(): void
    {
        $customer = $this->customer();
        $a = $this->makeIssuedInvoice($customer, '1000', '3.20');
        $b = $this->makeIssuedInvoice($customer, '1000', '3.20');
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'USD', 'payment_amount' => 1500, 'exchange_rate' => '3.30',
        ]);

        // Only $1,500 available but $2,000 requested.
        $this->expectException(RuntimeException::class);
        $this->service()->post($payment, [
            ['invoice_id' => $a->id, 'allocated_usd' => 1000],
            ['invoice_id' => $b->id, 'allocated_usd' => 1000],
        ]);
    }

    public function test_multi_invoice_allocation(): void
    {
        $customer = $this->customer();
        $a = $this->makeIssuedInvoice($customer, '1000', '3.20');
        $b = $this->makeIssuedInvoice($customer, '1500', '3.20');

        // 8,250 ILS / 3.30 = $2,500.
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS', 'payment_amount' => 8250, 'exchange_rate' => '3.30',
        ]);
        $this->service()->post($payment, [
            ['invoice_id' => $a->id, 'allocated_usd' => 1000],
            ['invoice_id' => $b->id, 'allocated_usd' => 1500],
        ]);

        $this->assertSame('2500.00', $payment->fresh()->usd_equivalent);
        $this->assertSame(InvoiceStatus::Paid, $a->fresh()->status);
        $this->assertSame(InvoiceStatus::Paid, $b->fresh()->status);
        $this->assertSame('0.00', $payment->fresh()->unallocatedUsd());
    }

    public function test_cross_customer_allocation_rejected(): void
    {
        $customerA = $this->customer();
        $customerB = $this->customer();
        $invoiceB = $this->makeIssuedInvoice($customerB, '1000', '3.20');

        $payment = $this->service()->createDraft([
            'customer_id' => $customerA->id, 'payment_currency' => 'USD', 'payment_amount' => 1000, 'exchange_rate' => '3.30',
        ]);

        $this->expectException(RuntimeException::class);
        $this->service()->post($payment, [['invoice_id' => $invoiceB->id, 'allocated_usd' => 1000]]);
    }

    public function test_paid_invoice_rejects_further_allocation(): void
    {
        $customer = $this->customer();
        $invoice = $this->makeIssuedInvoice($customer, '1000', '3.20');
        $p1 = $this->service()->createDraft(['customer_id' => $customer->id, 'payment_currency' => 'USD', 'payment_amount' => 1000, 'exchange_rate' => '3.30']);
        $this->service()->post($p1, [['invoice_id' => $invoice->id, 'allocated_usd' => 1000]]);

        $p2 = $this->service()->createDraft(['customer_id' => $customer->id, 'payment_currency' => 'USD', 'payment_amount' => 100, 'exchange_rate' => '3.30']);
        $this->expectException(RuntimeException::class);
        $this->service()->post($p2, [['invoice_id' => $invoice->id, 'allocated_usd' => 100]]);
    }

    // ---- Customer credit ----

    public function test_unallocated_credit_is_tracked_and_balance_is_correct(): void
    {
        $customer = $this->customer();
        $invoice = $this->makeIssuedInvoice($customer, '1500', '3.20');

        // Pay $2,000 but allocate only $1,500 → $500 credit.
        $payment = $this->service()->createDraft(['customer_id' => $customer->id, 'payment_currency' => 'USD', 'payment_amount' => 2000, 'exchange_rate' => '3.30']);
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => 1500]]);

        $this->assertSame('500.00', $payment->fresh()->unallocatedUsd());

        $balance = app(CustomerBalanceService::class);
        $this->assertSame('0.00', $balance->outstandingUsd($customer));
        $this->assertSame('500.00', $balance->unallocatedCreditUsd($customer));
        $this->assertSame('-500.00', $balance->netBalanceUsd($customer)); // customer is in credit
    }

    // ---- Exchange gain / loss ----

    public function test_exchange_gain(): void
    {
        $customer = $this->customer();
        $invoice = $this->makeIssuedInvoice($customer, '1000', '3.20'); // invoice rate 3.20
        $payment = $this->service()->createDraft(['customer_id' => $customer->id, 'payment_currency' => 'ILS', 'payment_amount' => 3300, 'exchange_rate' => '3.30']);
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => 1000]]);

        $alloc = PaymentAllocation::first();
        // $1,000 × 3.30 − $1,000 × 3.20 = +100 ILS.
        $this->assertSame('3200.00', $alloc->invoice_accounting_value_ils);
        $this->assertSame('3300.00', $alloc->payment_accounting_value_ils);
        $this->assertSame('100.00', $alloc->exchange_difference_ils);
    }

    public function test_exchange_loss(): void
    {
        $customer = $this->customer();
        $invoice = $this->makeIssuedInvoice($customer, '1000', '3.30'); // invoice rate 3.30
        $payment = $this->service()->createDraft(['customer_id' => $customer->id, 'payment_currency' => 'ILS', 'payment_amount' => 3200, 'exchange_rate' => '3.20']);
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => 1000]]);

        $alloc = PaymentAllocation::first();
        // $1,000 × 3.20 − $1,000 × 3.30 = −100 ILS.
        $this->assertSame('-100.00', $alloc->exchange_difference_ils);
    }

    public function test_partial_exchange_difference_only_on_allocated_portion(): void
    {
        $customer = $this->customer();
        $invoice = $this->makeIssuedInvoice($customer, '3000', '3.20');
        $payment = $this->service()->createDraft(['customer_id' => $customer->id, 'payment_currency' => 'ILS', 'payment_amount' => 3300, 'exchange_rate' => '3.30']);
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => 1000]]);

        $alloc = PaymentAllocation::first();
        // Difference on the $1,000 allocated only: +100 ILS.
        $this->assertSame('100.00', $alloc->exchange_difference_ils);
    }

    public function test_exchange_difference_uses_payment_rate_not_latest_global(): void
    {
        $customer = $this->customer();
        $invoice = $this->makeIssuedInvoice($customer, '1000', '3.20');
        $payment = $this->service()->createDraft(['customer_id' => $customer->id, 'payment_currency' => 'ILS', 'payment_amount' => 3300, 'exchange_rate' => '3.30']);
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => 1000]]);

        // Change the global rate table afterwards — allocation is unaffected.
        app(ExchangeRateService::class)->set(['rate_date' => '2026-08-29', 'rate' => '9.99']);

        $this->assertSame('100.00', PaymentAllocation::first()->fresh()->exchange_difference_ils);
    }
}
