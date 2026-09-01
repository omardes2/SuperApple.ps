<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Enums\SystemAccountKey;
use App\Livewire\Admin\InvoicesIndex;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use App\Services\AccountingService;
use App\Services\CustomerBalanceService;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\ReconciliationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Safe invoice deletion: a payment-free invoice can be removed from the active
 * record; an issued one has its issue journal reversed first (Revenue + AR back
 * to zero) with the original + reversal journals kept. Any invoice tied to a
 * payment (allocation active OR reversed, or a paid amount) is refused — in the
 * backend, not just the UI. No payment is touched, no cash moves, and no new
 * accounting is invented.
 */
class InvoiceSafeDeleteTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    private function svc(): InvoiceService
    {
        return app(InvoiceService::class);
    }

    private function draftInvoice(): Invoice
    {
        return $this->svc()->createDraft(
            ['customer_id' => $this->makeCustomer()->id, 'exchange_rate' => '3.20'],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price_usd' => 100]]);
    }

    /** Net (debit − credit) posted to a system account across the whole ledger. */
    private function accountNet(SystemAccountKey $key): float
    {
        $id = app(AccountingService::class)->systemAccountId($key);
        $lines = JournalEntryLine::where('account_id', $id)->get();

        return round((float) $lines->sum('debit_ils') - (float) $lines->sum('credit_ils'), 2);
    }

    private function payInFull(Invoice $invoice, string $usd, string $rate): Payment
    {
        $cash = $this->makeCashAccount('ILS');
        $svc = app(PaymentService::class);
        $ils = number_format(((float) $usd) * ((float) $rate), 2, '.', '');
        $payment = $svc->createDraft([
            'account_id' => $cash->id, 'customer_id' => $invoice->customer_id,
            'payment_currency' => 'ILS', 'payment_amount' => $ils, 'exchange_rate' => $rate,
            'payment_date' => '2026-08-05',
        ]);

        return $svc->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => $usd]]);
    }

    // ---------------------------------------------------------------- Draft

    public function test_delete_draft_removes_invoice_and_items(): void
    {
        $invoice = $this->draftInvoice();
        $this->assertTrue($invoice->items()->exists());

        $this->svc()->delete($invoice);

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
        $this->assertDatabaseMissing('invoice_items', ['invoice_id' => $invoice->id]); // items gone
        $this->assertDatabaseHas('audit_logs', ['action' => 'invoice_deleted']);
    }

    // --------------------------------------------------- Issued / Overdue unpaid

    public function test_delete_issued_unpaid_reverses_revenue_and_ar_to_zero(): void
    {
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '170.00', '3.04');

        // Posted issue journal exists before deletion.
        $this->assertTrue(app(AccountingService::class)->hasLivePosting('invoice', $invoice->id, 'invoice_issue'));

        $this->svc()->delete($invoice);

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
        // No live posting remains, but original + reversal journals are kept.
        $this->assertFalse(app(AccountingService::class)->hasLivePosting('invoice', $invoice->id, 'invoice_issue'));
        $this->assertDatabaseHas('journal_entries', ['source_type' => 'invoice', 'source_id' => $invoice->id, 'posting_type' => 'invoice_issue', 'status' => 'reversed']);
        $this->assertDatabaseHas('journal_entries', ['source_type' => 'invoice', 'source_id' => $invoice->id, 'posting_type' => 'invoice_issue_reversal']);
        // Revenue and AR net to zero across the ledger.
        $this->assertSame(0.0, $this->accountNet(SystemAccountKey::ServiceRevenue));
        $this->assertSame(0.0, $this->accountNet(SystemAccountKey::AccountsReceivable));
        // Reconciliation holds.
        $this->assertTrue(app(ReconciliationService::class)->accountsReceivable()['balanced']);
        $this->assertTrue(app(ReconciliationService::class)->cash()['balanced']);
    }

    public function test_delete_overdue_unpaid_is_allowed(): void
    {
        // makeIssuedInvoice due date is in the past → effective status Overdue.
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '90.00', '3.10');

        $this->svc()->delete($invoice);

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
        $this->assertTrue(app(ReconciliationService::class)->accountsReceivable()['balanced']);
    }

    public function test_customer_outstanding_drops_after_delete(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '200.00', '3.00');
        $this->assertSame('200.00', app(CustomerBalanceService::class)->outstandingUsd($customer));

        $this->svc()->delete($invoice->fresh());

        $this->assertSame('0.00', app(CustomerBalanceService::class)->outstandingUsd($customer->fresh()));
    }

    // ------------------------------------------------- Paid / Partial → refused

    public function test_fully_paid_invoice_cannot_be_deleted_and_payment_survives(): void
    {
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '170.00', '3.04');
        $payment = $this->payInFull($invoice, '170.00', '3.04');

        try {
            $this->svc()->delete($invoice->fresh());
            $this->fail('Expected the paid invoice delete to be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('دفعات', $e->getMessage());
        }

        // Invoice, payment and cash all intact.
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
        $this->assertTrue(app(ReconciliationService::class)->cash()['balanced']);
        $this->assertTrue(app(ReconciliationService::class)->accountsReceivable()['balanced']);
    }

    public function test_partially_paid_invoice_cannot_be_deleted(): void
    {
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '170.00', '3.04');
        $cash = $this->makeCashAccount('ILS');
        $svc = app(PaymentService::class);
        $p = $svc->createDraft([
            'account_id' => $cash->id, 'customer_id' => $invoice->customer_id,
            'payment_currency' => 'ILS', 'payment_amount' => '152.00', 'exchange_rate' => '3.04', 'payment_date' => '2026-08-05',
        ]);
        $svc->post($p, [['invoice_id' => $invoice->id, 'allocated_usd' => '50.00']]);

        $this->expectException(\RuntimeException::class);
        $this->svc()->delete($invoice->fresh());
    }

    public function test_invoice_with_reversed_allocation_still_cannot_be_deleted(): void
    {
        // Pay, then cancel the payment → allocation row becomes 'reversed' and the
        // invoice returns to unpaid, but a payment allocation still exists.
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '170.00', '3.04');
        $payment = $this->payInFull($invoice, '170.00', '3.04');
        app(PaymentService::class)->cancel($payment->fresh(), $this->makeUser(RoleName::GeneralManager), 'خطأ');

        $this->assertSame(0.0, (float) $invoice->fresh()->paid_usd_equivalent); // unpaid again
        $this->assertTrue($invoice->allocations()->exists());                    // but allocation row remains

        $this->expectException(\RuntimeException::class);
        $this->svc()->delete($invoice->fresh());
    }

    public function test_usd_payment_invoice_cannot_be_deleted(): void
    {
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '100.00', '3.50');
        $cash = $this->makeCashAccount('USD');
        $svc = app(PaymentService::class);
        $p = $svc->createDraft([
            'account_id' => $cash->id, 'customer_id' => $invoice->customer_id,
            'payment_currency' => 'USD', 'payment_amount' => '100.00', 'exchange_rate' => '3.50', 'payment_date' => '2026-08-05',
        ]);
        $svc->post($p, [['invoice_id' => $invoice->id, 'allocated_usd' => '100.00']]);

        $this->expectException(\RuntimeException::class);
        $this->svc()->delete($invoice->fresh());
    }

    // ------------------------------------------------------------ Cancelled

    public function test_cancelled_invoice_delete_does_not_double_reverse(): void
    {
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '120.00', '3.20');
        $this->svc()->cancel($invoice, 'خطأ إدخال'); // reverses the issue journal once

        $this->svc()->delete($invoice->fresh());

        // Exactly one reversal journal — the delete did not create a second one.
        $this->assertSame(1, JournalEntry::where('source_type', 'invoice')->where('source_id', $invoice->id)
            ->where('posting_type', 'invoice_issue_reversal')->count());
        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
        $this->assertTrue(app(ReconciliationService::class)->accountsReceivable()['balanced']);
    }

    // ------------------------------------------------------- Authorization

    public function test_unauthorized_user_cannot_delete(): void
    {
        $viewer = $this->makeUser(RoleName::Employee);
        $viewer->givePermissionTo('invoices.view'); // can open the list, cannot edit/cancel
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '100.00', '3.20');

        $this->assertFalse($viewer->can('delete', $invoice));

        $this->actingAs($viewer);
        Livewire::test(InvoicesIndex::class)
            ->call('openDelete', $invoice->id)
            ->assertForbidden();
    }

    // ------------------------------------------------------------ Concurrency

    public function test_double_delete_is_safe(): void
    {
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '80.00', '3.10');
        $stale = $invoice->fresh();

        $this->svc()->delete($invoice->fresh());
        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);

        // A second delete of the now-gone row cannot double-process (locked read
        // finds nothing) — no second reversal is produced.
        try {
            $this->svc()->delete($stale);
            $this->fail('Second delete should not silently succeed.');
        } catch (ModelNotFoundException) {
            // expected
        }
        $this->assertSame(1, JournalEntry::where('source_type', 'invoice')->where('source_id', $invoice->id)
            ->where('posting_type', 'invoice_issue_reversal')->count());
    }

    // ------------------------------------------------------------ Livewire flow

    public function test_livewire_delete_issued_unpaid_flow(): void
    {
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '140.00', '3.15');

        Livewire::test(InvoicesIndex::class)
            ->call('openDelete', $invoice->id)
            ->assertSet('showDelete', true)
            ->assertSet('deleteIsDraft', false)
            ->call('confirmDelete')
            ->assertSet('showDelete', false)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
    }
}
