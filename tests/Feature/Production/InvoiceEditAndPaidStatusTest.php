<?php

namespace Tests\Feature\Production;

use App\Enums\InvoiceStatus;
use App\Enums\RoleName;
use App\Livewire\Admin\InvoicesIndex;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Paying an invoice must flip it to Paid, the invoices list must flag paid vs
 * unpaid, and an issued invoice must be editable the accounting-correct way:
 * reverting it to a draft reverses its journal (keeping the number) and is
 * blocked once payments are allocated.
 */
class InvoiceEditAndPaidStatusTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    private function invoices(): InvoiceService
    {
        return app(InvoiceService::class);
    }

    private function payments(): PaymentService
    {
        return app(PaymentService::class);
    }

    /** Fully settle an invoice with a matching USD payment. */
    private function payInFull(Customer $customer, Invoice $invoice, string $usd): void
    {
        $cash = $this->makeCashAccount('USD');
        $payment = $this->payments()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'USD',
            'payment_amount' => $usd, 'exchange_rate' => '3.10', 'account_id' => $cash->id,
        ]);
        $this->payments()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => $usd]]);
    }

    // ---- Payment → Paid ----

    public function test_full_payment_marks_invoice_paid(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '170.00', '3.10');
        $this->assertSame(InvoiceStatus::Issued, $invoice->status);

        $this->payInFull($customer, $invoice, '170.00');

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame('0.00', $invoice->remaining_usd);
    }

    // ---- Paid / unpaid badge on the list ----

    public function test_unpaid_invoice_shows_unpaid_badge(): void
    {
        $customer = $this->makeCustomer();
        $this->makeIssuedInvoice($customer, '170.00', '3.10');

        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))->test(InvoicesIndex::class)
            ->assertOk()
            ->assertSee('غير مدفوعة');
    }

    public function test_paid_invoice_shows_paid_badge(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '170.00', '3.10');
        $this->payInFull($customer, $invoice, '170.00');

        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))->test(InvoicesIndex::class)
            ->assertSee('مدفوعة');
    }

    // ---- Accounting-correct edit of an issued invoice ----

    public function test_issued_invoice_can_be_reverted_to_draft_keeping_number(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '170.00', '3.10');
        $number = $invoice->invoice_number;
        $this->assertSame(1, JournalEntry::where('source_type', 'invoice')->where('source_id', $invoice->id)->count());

        $this->invoices()->revertToDraft($invoice);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertNull($invoice->issued_at);
        $this->assertSame($number, $invoice->invoice_number); // number preserved
    }

    public function test_revert_reverses_the_issue_journal(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '170.00', '3.10');

        $this->invoices()->revertToDraft($invoice);

        // The AR/revenue impact nets to zero once the issue journal is reversed.
        $lines = JournalEntry::where('source_type', 'invoice')->where('source_id', $invoice->id)
            ->with('lines')->get()->flatMap->lines;
        $this->assertSame(
            (float) $lines->sum('debit_ils'),
            (float) $lines->sum('credit_ils'),
        );
    }

    public function test_reverted_invoice_can_be_reissued_with_a_fresh_journal(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '170.00', '3.10');

        $this->invoices()->revertToDraft($invoice);
        $reissued = $this->invoices()->issue($invoice->fresh());

        $this->assertSame(InvoiceStatus::Issued, $reissued->status);
        $this->assertSame('170.00', $reissued->remaining_usd);
    }

    public function test_edit_component_action_reverts_issued_invoice(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '170.00', '3.10');

        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))->test(InvoicesIndex::class)
            ->call('editInvoice', $invoice->id)
            ->assertRedirect(route('admin.invoices.show', $invoice));

        $this->assertSame(InvoiceStatus::Draft, $invoice->fresh()->status);
    }

    public function test_issued_invoice_with_payment_cannot_be_edited(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '170.00', '3.10');
        $this->payInFull($customer, $invoice, '170.00'); // now Paid, has active allocation

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('دفعات مخصصة نشطة');
        $this->invoices()->revertToDraft($invoice->fresh());
    }

    public function test_edit_action_blocks_paid_invoice_via_policy(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '170.00', '3.10');
        $this->payInFull($customer, $invoice, '170.00');

        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))->test(InvoicesIndex::class)
            ->call('editInvoice', $invoice->id)
            ->assertForbidden();

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    public function test_cancelled_invoice_cannot_be_reverted(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '170.00', '3.10');
        $this->invoices()->cancel($invoice, 'خطأ');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ملغاة');
        $this->invoices()->revertToDraft($invoice->fresh());
    }

    public function test_revert_writes_an_audit_entry(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '170.00', '3.10');
        $this->invoices()->revertToDraft($invoice);

        $this->assertDatabaseHas('audit_logs', ['action' => 'invoice_reverted_to_draft']);
    }
}
