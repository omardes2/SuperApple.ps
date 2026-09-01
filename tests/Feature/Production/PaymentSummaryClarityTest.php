<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Livewire\Admin\PaymentShow;
use App\Models\Invoice;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The draft payment page shows a pre-post "نتيجة الدفعة" summary that classifies
 * the outcome (partial / exact / overpayment) using only the official values the
 * payment & allocation services already expose. No accounting logic is touched.
 */
class PaymentSummaryClarityTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    /**
     * A draft ILS payment against a $170 @ 3.04 invoice, with a manual
     * allocation of $allocateUsd, at ILS amount $ils.
     */
    private function draftAgainstInvoice(string $ils, string $allocateUsd): array
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '170.00', '3.04');
        $payment = app(PaymentService::class)->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => $ils, 'exchange_rate' => '3.04',
        ]);

        $comp = Livewire::actingAs($this->makeUser(RoleName::GeneralManager))
            ->test(PaymentShow::class, ['payment' => $payment])
            ->set('payment_currency', 'ILS')
            ->set('exchange_rate', '3.04')
            ->set('payment_amount', $ils)
            ->set('allocations', [['invoice_id' => $invoice->id, 'opening_balance_id' => null, 'allocated_usd' => $allocateUsd]]);

        return [$comp, $invoice];
    }

    public function test_partial_payment_summary(): void
    {
        // Pay 304 ₪ = $100 against a $170 invoice → $70 remaining.
        [$comp] = $this->draftAgainstInvoice('304.00', '100.00');

        $ps = $comp->viewData('paymentSummary');
        $this->assertSame('partial', $ps['state']);
        $this->assertSame('304.00', $ps['received']);
        $this->assertSame('100.00', $ps['allocated_usd']);
        $this->assertSame('304.00', $ps['allocated_ils']);
        $this->assertSame('70.00', $ps['remaining_after_usd']);
        $this->assertSame('0.00', $ps['surplus_usd']);

        $comp->assertSee('المتبقي على الفاتورة بعد الدفع');
    }

    public function test_exact_full_payment_summary(): void
    {
        // Pay 516.80 ₪ = $170 → settles exactly, no surplus.
        [$comp] = $this->draftAgainstInvoice('516.80', '170.00');

        $ps = $comp->viewData('paymentSummary');
        $this->assertSame('exact', $ps['state']);
        $this->assertSame('170.00', $ps['allocated_usd']);
        $this->assertSame('516.80', $ps['allocated_ils']);
        $this->assertSame('0.00', $ps['remaining_after_usd']);
        $this->assertSame('0.00', $ps['surplus_usd']);
        $this->assertNull($ps['surplus_original_ils']);

        $comp->assertSee('سيتم تسديد الفاتورة بالكامل');
    }

    public function test_overpayment_summary(): void
    {
        // Pay 527 ₪ (= $173.36) but allocate only the $170 remaining → surplus.
        [$comp] = $this->draftAgainstInvoice('527.00', '170.00');

        $ps = $comp->viewData('paymentSummary');
        $this->assertSame('overpayment', $ps['state']);
        $this->assertSame('527.00', $ps['received']);
        $this->assertSame('170.00', $ps['allocated_usd']);
        $this->assertSame('516.80', $ps['allocated_ils']);
        $this->assertSame('3.36', $ps['surplus_usd']);          // 173.36 − 170.00
        $this->assertSame('10.20', $ps['surplus_original_ils']); // 527.00 − 516.80

        $comp->assertSee('الرصيد الزائد')
            ->assertSee('سيُحفظ المبلغ الزائد كرصيد دائن غير مخصص للعميل ويمكن استخدامه لاحقاً.');
    }

    public function test_summary_hidden_when_nothing_allocated_yet(): void
    {
        $customer = $this->makeCustomer();
        $payment = app(PaymentService::class)->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'USD', 'payment_amount' => '0',
        ]);

        $ps = Livewire::actingAs($this->makeUser(RoleName::GeneralManager))
            ->test(PaymentShow::class, ['payment' => $payment])
            ->viewData('paymentSummary');

        $this->assertSame('none', $ps['state']);
    }

    public function test_summary_does_not_change_posting_result(): void
    {
        // Guard: the summary is display-only. Posting the overpayment still
        // settles the invoice and leaves the surplus as customer credit.
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '170.00', '3.04');
        $cash = $this->makeCashAccount('ILS');
        $svc = app(PaymentService::class);
        $payment = $svc->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => '527.00', 'exchange_rate' => '3.04', 'account_id' => $cash->id,
        ]);
        $svc->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => '170.00']]);

        $this->assertSame('0.00', $invoice->fresh()->remaining_usd);           // invoice settled
        $this->assertSame('3.36', $payment->fresh()->unallocatedUsd());        // surplus as credit
    }
}
