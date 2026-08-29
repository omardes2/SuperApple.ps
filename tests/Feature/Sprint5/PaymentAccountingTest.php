<?php

namespace Tests\Feature\Sprint5;

use App\Enums\RoleName;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\PaymentService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class PaymentAccountingTest extends TestCase
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

    private function paymentJournal(int $paymentId): JournalEntry
    {
        return JournalEntry::with('lines')->where('source_type', 'payment')
            ->where('source_id', $paymentId)->where('posting_type', 'payment_receipt')->firstOrFail();
    }

    private function line(JournalEntry $entry, string $code): ?JournalEntryLine
    {
        return $entry->lines->firstWhere('account_id', Account::where('code', $code)->value('id'));
    }

    public function test_ils_payment_gain_journal_is_correct(): void
    {
        // Invoice $1,000 @ 3.20 → AR 3,200. Pay 3,300 ILS @ 3.30.
        // Debit Cash 3,300 ; Credit AR 3,200 ; Credit Exchange Gain 100.
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '1000', '3.20');
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => 3300, 'exchange_rate' => '3.30',
        ]);
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => 1000]]);

        $entry = $this->paymentJournal($payment->id);
        $this->assertSame('3300.00', $this->line($entry, '1110')->debit_ils);   // cash
        $this->assertSame('3200.00', $this->line($entry, '1200')->credit_ils);  // AR
        $this->assertSame('100.00', $this->line($entry, '4900')->credit_ils);   // exchange gain
        $this->assertSame('3300.00', $entry->totalDebit());
    }

    public function test_ils_payment_loss_journal_is_correct(): void
    {
        // Invoice $1,000 @ 3.30 → AR 3,300. Pay 3,200 ILS @ 3.20.
        // Debit Cash 3,200 ; Debit Exchange Loss 100 ; Credit AR 3,300.
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '1000', '3.30');
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => 3200, 'exchange_rate' => '3.20',
        ]);
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => 1000]]);

        $entry = $this->paymentJournal($payment->id);
        $this->assertSame('3200.00', $this->line($entry, '1110')->debit_ils);   // cash
        $this->assertSame('100.00', $this->line($entry, '5950')->debit_ils);    // exchange loss
        $this->assertSame('3300.00', $this->line($entry, '1200')->credit_ils);  // AR
    }

    public function test_usd_payment_valuation_is_correct(): void
    {
        // Invoice $1,000 @ 3.20 → AR 3,200. Pay $1,000 @ 3.30.
        // Cash accounting value 3,300 ; AR 3,200 ; Gain 100.
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '1000', '3.20');
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'USD',
            'payment_amount' => 1000, 'exchange_rate' => '3.30',
        ]);
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => 1000]]);

        $entry = $this->paymentJournal($payment->id);
        $this->assertSame('3300.00', $this->line($entry, '1120')->debit_ils); // USD cash, ILS value
        $this->assertSame('USD', $this->line($entry, '1120')->original_currency);
        $this->assertSame('1000.00', $this->line($entry, '1120')->original_amount);
        $this->assertSame('100.00', $this->line($entry, '4900')->credit_ils);
    }

    public function test_multi_invoice_payment_produces_correct_ar_lines(): void
    {
        $customer = $this->makeCustomer();
        $a = $this->makeIssuedInvoice($customer, '1000', '3.20');
        $b = $this->makeIssuedInvoice($customer, '1500', '3.20');
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'USD',
            'payment_amount' => 2500, 'exchange_rate' => '3.20',
        ]);
        $this->service()->post($payment, [
            ['invoice_id' => $a->id, 'allocated_usd' => 1000],
            ['invoice_id' => $b->id, 'allocated_usd' => 1500],
        ]);

        $entry = $this->paymentJournal($payment->id);
        $arLines = $entry->lines->where('account_id', Account::where('code', '1200')->value('id'));
        $this->assertCount(2, $arLines);
        $this->assertSame('8000.00', Money::sum($arLines->pluck('credit_ils')));
        $this->assertTrue($entry->lines->pluck('invoice_id')->filter()->contains($a->id));
    }

    public function test_unallocated_payment_creates_customer_credit_liability(): void
    {
        // Pay $2,000, allocate $1,500 → $500 credit at rate 3.30 = 1,650 ILS.
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '1500', '3.30');
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'USD',
            'payment_amount' => 2000, 'exchange_rate' => '3.30',
        ]);
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => 1500]]);

        $entry = $this->paymentJournal($payment->id);
        $this->assertSame('1650.00', $this->line($entry, '2300')->credit_ils); // customer credits
    }

    public function test_exchange_gain_is_not_revenue(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '1000', '3.20');
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => 3300, 'exchange_rate' => '3.30',
        ]);
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => 1000]]);

        $entry = $this->paymentJournal($payment->id);
        // The gain hits 4900 (Exchange Gain), never 4100 (Service Revenue).
        $this->assertNull($this->line($entry, '4100'));
        $this->assertSame('100.00', $this->line($entry, '4900')->credit_ils);
    }

    public function test_payment_cancellation_creates_reversal_and_keeps_original(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '1000', '3.20');
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => 3300, 'exchange_rate' => '3.30',
        ]);
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => 1000]]);

        $this->service()->cancel($payment->fresh(), $this->makeUser(RoleName::Accountant), 'خطأ');

        $original = $this->paymentJournal($payment->id);
        $this->assertSame('reversed', $original->fresh()->status->value);
        $this->assertDatabaseHas('journal_entries', ['posting_type' => 'payment_receipt_reversal', 'source_id' => $payment->id]);
        // The original journal row is never deleted.
        $this->assertDatabaseHas('journal_entries', ['id' => $original->id]);
    }
}
