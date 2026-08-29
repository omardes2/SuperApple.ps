<?php

namespace Tests\Feature\Sprint4;

use App\Enums\InvoiceStatus;
use App\Enums\RoleName;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\CustomerStatementService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class PaymentIntegrityTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::Accountant));
    }

    private function service(): PaymentService
    {
        return app(PaymentService::class);
    }

    public function test_payment_numbers_are_unique_and_sequential(): void
    {
        $customer = $this->makeCustomer();
        $a = $this->service()->createDraft(['customer_id' => $customer->id, 'payment_currency' => 'USD', 'payment_amount' => 100, 'exchange_rate' => '3.30']);
        $b = $this->service()->createDraft(['customer_id' => $customer->id, 'payment_currency' => 'USD', 'payment_amount' => 100, 'exchange_rate' => '3.30']);

        $this->assertNotSame($a->payment_number, $b->payment_number);
        $this->assertStringStartsWith('PAY-', $a->payment_number);
        $this->assertSame(2, Payment::whereIn('payment_number', [$a->payment_number, $b->payment_number])->distinct('payment_number')->count('payment_number'));
    }

    public function test_number_not_reused_after_cancel(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '1000', '3.20');
        $p1 = $this->service()->createDraft(['customer_id' => $customer->id, 'payment_currency' => 'USD', 'payment_amount' => 1000, 'exchange_rate' => '3.30']);
        $this->service()->post($p1, [['invoice_id' => $invoice->id, 'allocated_usd' => 1000]]);
        $this->service()->cancel($p1->fresh(), auth()->user(), 'خطأ');

        $p2 = $this->service()->createDraft(['customer_id' => $customer->id, 'payment_currency' => 'USD', 'payment_amount' => 500, 'exchange_rate' => '3.30']);
        $this->assertNotSame($p1->payment_number, $p2->payment_number);
    }

    public function test_failed_allocation_rolls_back_the_whole_post(): void
    {
        $customer = $this->makeCustomer();
        $good = $this->makeIssuedInvoice($customer, '1000', '3.20');
        $other = $this->makeIssuedInvoice($this->makeCustomer(), '1000', '3.20'); // different customer → invalid

        $payment = $this->service()->createDraft(['customer_id' => $customer->id, 'payment_currency' => 'USD', 'payment_amount' => 2000, 'exchange_rate' => '3.30']);

        try {
            $this->service()->post($payment, [
                ['invoice_id' => $good->id, 'allocated_usd' => 1000],
                ['invoice_id' => $other->id, 'allocated_usd' => 1000],
            ]);
            $this->fail('Expected the cross-customer allocation to throw.');
        } catch (RuntimeException) {
            // expected
        }

        // Nothing must have persisted: no allocations, invoice untouched, payment still draft.
        $this->assertSame(0, PaymentAllocation::count());
        $this->assertSame('0.00', $good->fresh()->paid_usd_equivalent);
        $this->assertSame(InvoiceStatus::Issued, $good->fresh()->status);
        $this->assertTrue($payment->fresh()->isDraft());
    }

    public function test_remaining_never_goes_negative_within_tolerance(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '1000', '3.20');
        $payment = $this->service()->createDraft(['customer_id' => $customer->id, 'payment_currency' => 'USD', 'payment_amount' => 1000, 'exchange_rate' => '3.30']);
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => 1000]]);

        $invoice->refresh();
        $this->assertSame('0.00', $invoice->remaining_usd);
        $this->assertFalse((float) $invoice->remaining_usd < 0);
    }

    public function test_auto_allocate_plan_orders_oldest_due_first(): void
    {
        $customer = $this->makeCustomer();
        // Two invoices; the one with the earlier due date must be filled first.
        $newer = $this->makeIssuedInvoice($customer, '1000', '3.20');
        $older = $this->makeIssuedInvoice($customer, '1000', '3.20');
        $newer->forceFill(['due_date' => '2026-12-31'])->saveQuietly();
        $older->forceFill(['due_date' => '2026-09-01'])->saveQuietly();

        $payment = $this->service()->createDraft(['customer_id' => $customer->id, 'payment_currency' => 'USD', 'payment_amount' => 1200, 'exchange_rate' => '3.30']);
        $plan = $this->service()->autoAllocatePlan($payment);

        $this->assertSame($older->id, $plan[0]['invoice_id']);
        $this->assertSame('1000.00', $plan[0]['allocated_usd']); // older filled in full
        $this->assertSame($newer->id, $plan[1]['invoice_id']);
        $this->assertSame('200.00', $plan[1]['allocated_usd']);  // remainder to newer
    }

    public function test_statement_does_not_double_count_multi_invoice_payment(): void
    {
        $customer = $this->makeCustomer();
        $a = $this->makeIssuedInvoice($customer, '1000', '3.20');
        $b = $this->makeIssuedInvoice($customer, '1500', '3.20');

        // One payment settles both invoices — appears once as a $2,500 credit.
        $payment = $this->service()->createDraft(['customer_id' => $customer->id, 'payment_currency' => 'USD', 'payment_amount' => 2500, 'exchange_rate' => '3.30']);
        $this->service()->post($payment, [
            ['invoice_id' => $a->id, 'allocated_usd' => 1000],
            ['invoice_id' => $b->id, 'allocated_usd' => 1500],
        ]);

        $statement = app(CustomerStatementService::class)->build($customer);
        $credits = array_filter($statement['entries'], fn ($r) => $r['type'] === 'payment');

        $this->assertCount(1, $credits);
        $this->assertSame('2500.00', array_values($credits)[0]['credit_usd']);
        // 2500 invoiced − 2500 paid = 0 closing balance.
        $this->assertSame('0.00', $statement['closing_balance_usd']);
    }

    public function test_statement_excludes_cancelled_payment(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '1000', '3.20');
        $payment = $this->service()->createDraft(['customer_id' => $customer->id, 'payment_currency' => 'USD', 'payment_amount' => 1000, 'exchange_rate' => '3.30']);
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => 1000]]);
        $this->service()->cancel($payment->fresh(), auth()->user(), 'خطأ');

        $statement = app(CustomerStatementService::class)->build($customer);
        $credits = array_filter($statement['entries'], fn ($r) => $r['type'] === 'payment');

        $this->assertCount(0, $credits);
        // Only the $1,000 invoice remains outstanding.
        $this->assertSame('1000.00', $statement['closing_balance_usd']);
    }
}
