<?php

namespace Tests\Feature\Sprint5;

use App\Enums\RoleName;
use App\Models\JournalEntry;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class InvoiceCancellationTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::Accountant));
    }

    public function test_invoice_cancellation_reverses_issue_journal(): void
    {
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '1000', '3.30');
        $issue = JournalEntry::where('source_type', 'invoice')->where('source_id', $invoice->id)
            ->where('posting_type', 'invoice_issue')->firstOrFail();

        app(InvoiceService::class)->cancel($invoice, 'خطأ في الإصدار');

        $this->assertSame('reversed', $issue->fresh()->status->value);
        $this->assertDatabaseHas('journal_entries', ['posting_type' => 'invoice_issue_reversal', 'source_id' => $invoice->id]);
    }

    public function test_invoice_with_active_allocations_cannot_be_cancelled(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '1000', '3.30');
        $payment = app(PaymentService::class)->createDraft(['account_id' => $this->cashAccount('USD')->id,
            'customer_id' => $customer->id, 'payment_currency' => 'USD', 'payment_amount' => 1000, 'exchange_rate' => '3.30',
        ]);
        app(PaymentService::class)->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => 1000]]);

        $this->expectException(RuntimeException::class);
        app(InvoiceService::class)->cancel($invoice->fresh(), 'محاولة إلغاء');
    }

    public function test_reversed_invoice_journal_remains_historical(): void
    {
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '1000', '3.30');
        $issue = JournalEntry::where('source_type', 'invoice')->where('source_id', $invoice->id)->firstOrFail();

        app(InvoiceService::class)->cancel($invoice, 'خطأ');

        // Both the original (reversed) and the reversal journal survive.
        $this->assertDatabaseHas('journal_entries', ['id' => $issue->id, 'status' => 'reversed']);
        $this->assertSame(2, JournalEntry::where('source_id', $invoice->id)->where('source_type', 'invoice')->count());
    }
}
