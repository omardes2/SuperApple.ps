<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Services\AccountingService;
use App\Services\InvoiceService;
use App\Services\LedgerPostingService;
use App\Services\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Root cause: re-issuing an invoice (revert-to-draft → issue) used to skip its
 * GL journal because hasPosted() matched the reversed original, and the hard
 * unique index forbade a second live issue journal. That left the invoice live
 * in the sub-ledger with NO AR debit in the GL — AR GL drifted below the
 * sub-ledger by the invoice value. These tests pin the fix and the repair.
 */
class ArReconciliationReissueTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    private function recon(): ReconciliationService
    {
        return app(ReconciliationService::class);
    }

    public function test_haslive_posting_ignores_a_reversed_journal(): void
    {
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '170.00', '3.04');
        $accounting = app(AccountingService::class);

        $this->assertTrue($accounting->hasLivePosting('invoice', $invoice->id, 'invoice_issue'));
        $this->assertTrue($accounting->hasPosted('invoice', $invoice->id, 'invoice_issue'));

        app(LedgerPostingService::class)->reverseInvoiceIssue($invoice, 'test');

        // A reversed journal must NOT count as a LIVE posting — the document may
        // be re-posted — but hasPosted (any entry) still sees it (backfill guard).
        $this->assertFalse($accounting->hasLivePosting('invoice', $invoice->id, 'invoice_issue'));
        $this->assertTrue($accounting->hasPosted('invoice', $invoice->id, 'invoice_issue'));
    }

    public function test_revert_then_reissue_keeps_ar_balanced(): void
    {
        $svc = app(InvoiceService::class);
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '170.00', '3.04');

        $this->assertTrue($this->recon()->accountsReceivable()['balanced']);

        $svc->revertToDraft($invoice->fresh());
        $svc->issue($invoice->fresh());

        $ar = $this->recon()->accountsReceivable();
        $this->assertTrue($ar['balanced'], 'AR must stay balanced after revert + re-issue');
        $this->assertSame('516.80', $ar['gl_balance']);
        $this->assertSame('516.80', $ar['sub_ledger']);

        // A fresh live issue journal exists; the old one is reversed.
        $issues = JournalEntry::where('source_type', 'invoice')->where('source_id', $invoice->id)
            ->where('posting_type', 'invoice_issue')->get();
        $this->assertCount(2, $issues);
        $this->assertSame(1, $issues->where('status.value', 'posted')->count());
        $this->assertSame(1, $issues->where('status.value', 'reversed')->count());
    }

    public function test_reissue_posts_a_second_live_journal_without_unique_violation(): void
    {
        $svc = app(InvoiceService::class);
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '100.00', '3.20');

        // Two edit cycles in a row must not hit the (source, posting_type) unique.
        $svc->revertToDraft($invoice->fresh());
        $svc->issue($invoice->fresh());
        $svc->revertToDraft($invoice->fresh());
        $svc->issue($invoice->fresh());

        $this->assertTrue($this->recon()->accountsReceivable()['balanced']);
        $liveIssues = JournalEntry::where('source_type', 'invoice')->where('source_id', $invoice->id)
            ->where('posting_type', 'invoice_issue')->where('status', 'posted')->count();
        $this->assertSame(1, $liveIssues, 'exactly one live issue journal at a time');
    }

    /**
     * Build the exact corrupted state a production invoice is in after the old
     * bug: live invoice, but its issue journal is reversed and never re-posted.
     */
    private function corruptedInvoice(): Invoice
    {
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '170.00', '3.04');
        // Reverse the issue journal but leave the invoice live (mimics re-issue
        // that skipped its journal).
        app(LedgerPostingService::class)->reverseInvoiceIssue($invoice, 'simulate legacy bug');

        return $invoice->fresh();
    }

    public function test_repair_dry_run_reports_but_changes_nothing(): void
    {
        $invoice = $this->corruptedInvoice();
        $before = $this->recon()->accountsReceivable();
        $this->assertFalse($before['balanced']);
        $this->assertSame('-516.80', $before['difference']);

        $journalsBefore = JournalEntry::count();
        $code = Artisan::call('app:repair-ar-reconciliation'); // dry-run
        $this->assertSame(0, $code);

        // Nothing posted, still unbalanced.
        $this->assertSame($journalsBefore, JournalEntry::count());
        $this->assertFalse($this->recon()->accountsReceivable()['balanced']);
    }

    public function test_repair_execute_restores_ar_balance(): void
    {
        $invoice = $this->corruptedInvoice();
        $this->assertFalse($this->recon()->accountsReceivable()['balanced']);

        $code = Artisan::call('app:repair-ar-reconciliation', ['--execute' => true]);
        $this->assertSame(0, $code);

        $ar = $this->recon()->accountsReceivable();
        $this->assertTrue($ar['balanced'], 'AR must be balanced after repair --execute');
        $this->assertSame('516.80', $ar['gl_balance']);

        // A live issue journal now exists again for the invoice.
        $this->assertTrue(app(AccountingService::class)->hasLivePosting('invoice', $invoice->id, 'invoice_issue'));
    }

    public function test_repair_execute_keeps_integrity_green(): void
    {
        $this->corruptedInvoice();
        Artisan::call('app:repair-ar-reconciliation', ['--execute' => true]);

        $this->assertSame(0, Artisan::call('app:verify-integrity'));
    }

    public function test_diagnose_command_runs_read_only(): void
    {
        $this->corruptedInvoice();
        $journalsBefore = JournalEntry::count();

        $this->assertSame(0, Artisan::call('app:diagnose-ar-reconciliation'));
        $this->assertSame($journalsBefore, JournalEntry::count());
    }
}
