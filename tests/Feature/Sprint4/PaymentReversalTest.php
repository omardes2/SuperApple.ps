<?php

namespace Tests\Feature\Sprint4;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\RoleName;
use App\Exceptions\PostedPaymentImmutableException;
use App\Models\Customer;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class PaymentReversalTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actor = $this->makeUser(RoleName::Accountant);
        $this->actingAs($this->actor);
    }

    private function service(): PaymentService
    {
        return app(PaymentService::class);
    }

    private function paidScenario(Customer $customer, string $invoiceTotal = '1000'): array
    {
        $invoice = $this->makeIssuedInvoice($customer, $invoiceTotal, '3.20');
        $payment = $this->service()->createDraft(['account_id' => $this->cashAccount('ILS')->id,
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => (string) ((float) $invoiceTotal * 3.30), 'exchange_rate' => '3.30',
        ]);
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => $invoiceTotal]]);

        return [$invoice->fresh(), $payment->fresh()];
    }

    public function test_posted_payment_cannot_be_edited(): void
    {
        [, $payment] = $this->paidScenario($this->makeCustomer());

        $this->expectException(PostedPaymentImmutableException::class);
        $payment->update(['payment_amount' => '9999']);
    }

    public function test_cancel_requires_reason(): void
    {
        [, $payment] = $this->paidScenario($this->makeCustomer());

        $this->expectException(RuntimeException::class);
        $this->service()->cancel($payment, $this->actor, '');
    }

    public function test_cancel_restores_invoice_remaining_and_status(): void
    {
        $customer = $this->makeCustomer();
        [$invoice, $payment] = $this->paidScenario($customer, '1000');
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);

        $this->service()->cancel($payment, $this->actor, 'دفعة خاطئة');

        $invoice->refresh();
        $this->assertSame('0.00', $invoice->paid_usd_equivalent);
        $this->assertSame('1000.00', $invoice->remaining_usd);
        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
    }

    public function test_cancel_reverses_allocation_but_keeps_history(): void
    {
        $customer = $this->makeCustomer();
        [, $payment] = $this->paidScenario($customer, '1000');

        $this->service()->cancel($payment, $this->actor, 'خطأ');

        $payment->refresh();
        $this->assertSame(PaymentStatus::Cancelled, $payment->status);
        // The allocation row is kept, marked reversed (never hard-deleted).
        $alloc = PaymentAllocation::first();
        $this->assertSame(PaymentAllocation::STATUS_REVERSED, $alloc->status);
        $this->assertNotNull($alloc->reversed_at);
        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
    }

    public function test_cancel_restores_partially_paid_status(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '3000', '3.20');

        // Two payments: $1,000 then $500 → invoice PartiallyPaid at $1,500 paid.
        $p1 = $this->service()->createDraft(['account_id' => $this->cashAccount('USD')->id, 'customer_id' => $customer->id, 'payment_currency' => 'USD', 'payment_amount' => 1000, 'exchange_rate' => '3.30']);
        $this->service()->post($p1, [['invoice_id' => $invoice->id, 'allocated_usd' => 1000]]);
        $p2 = $this->service()->createDraft(['account_id' => $this->cashAccount('USD')->id, 'customer_id' => $customer->id, 'payment_currency' => 'USD', 'payment_amount' => 500, 'exchange_rate' => '3.30']);
        $this->service()->post($p2, [['invoice_id' => $invoice->id, 'allocated_usd' => 500]]);

        $this->assertSame('1500.00', $invoice->fresh()->paid_usd_equivalent);

        // Cancel the second payment → back to $1,000 paid, still PartiallyPaid.
        $this->service()->cancel($p2, $this->actor, 'تصحيح');

        $invoice->refresh();
        $this->assertSame('1000.00', $invoice->paid_usd_equivalent);
        $this->assertSame('2000.00', $invoice->remaining_usd);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);
    }

    public function test_employee_cannot_cancel_payment(): void
    {
        $customer = $this->makeCustomer();
        [, $payment] = $this->paidScenario($customer, '1000');

        [$emp] = $this->makeStaff();
        $this->assertFalse($emp->can('cancel', $payment));
        $this->assertFalse($emp->can('payments.cancel'));
    }
}
