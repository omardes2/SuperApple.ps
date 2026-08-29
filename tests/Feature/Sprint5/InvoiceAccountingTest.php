<?php

namespace Tests\Feature\Sprint5;

use App\Enums\RoleName;
use App\Enums\SystemAccountKey;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\SystemAccount;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class InvoiceAccountingTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::Accountant));
    }

    private function lineFor(JournalEntry $entry, string $code): ?JournalEntryLine
    {
        $accountId = Account::where('code', $code)->value('id');

        return $entry->lines->firstWhere('account_id', $accountId);
    }

    private function issueJournal(int $invoiceId): JournalEntry
    {
        return JournalEntry::with('lines')->where('source_type', 'invoice')
            ->where('source_id', $invoiceId)->where('posting_type', 'invoice_issue')->firstOrFail();
    }

    public function test_invoice_issue_debits_ar_at_issue_rate(): void
    {
        // $2,000 @ 3.28 → AR 6,560 debit.
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '2000', '3.28');
        $entry = $this->issueJournal($invoice->id);

        $this->assertSame('6560.00', $this->lineFor($entry, '1200')->debit_ils);
    }

    public function test_revenue_is_credited(): void
    {
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '2000', '3.28');
        $entry = $this->issueJournal($invoice->id);

        // No tax → full amount is revenue.
        $this->assertSame('6560.00', $this->lineFor($entry, '4100')->credit_ils);
    }

    public function test_tax_is_separated_correctly(): void
    {
        // Subtotal $1,000, tax 17% = $170, total $1,170 @ 3.30.
        // AR 3,861 ; Revenue 3,300 ; Tax Payable 561.
        $invoice = $this->makeTaxedInvoice($this->makeCustomer(), '1000', 17, '3.30');
        $entry = $this->issueJournal($invoice->id);

        $this->assertSame('3861.00', $this->lineFor($entry, '1200')->debit_ils);
        $this->assertSame('3300.00', $this->lineFor($entry, '4100')->credit_ils);
        $this->assertSame('561.00', $this->lineFor($entry, '2200')->credit_ils);
    }

    public function test_invoice_journal_is_linked_to_invoice(): void
    {
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '1000', '3.30');
        $entry = $this->issueJournal($invoice->id);

        $this->assertSame($invoice->id, $entry->lines->first()->invoice_id);
        $this->assertSame('USD', $this->lineFor($entry, '1200')->original_currency);
    }

    public function test_issue_rolls_back_if_accounting_posting_fails(): void
    {
        // Remove the revenue system account so posting throws mid-issue.
        SystemAccount::where('key', SystemAccountKey::ServiceRevenue->value)->delete();

        $customer = $this->makeCustomer();
        $invoice = app(InvoiceService::class)->createDraft(
            ['customer_id' => $customer->id, 'invoice_date' => '2026-08-01', 'exchange_rate' => '3.30'],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price_usd' => '1000', 'tax_rate' => 0]],
        );

        try {
            app(InvoiceService::class)->issue($invoice);
            $this->fail('Expected issue to fail when accounts are missing.');
        } catch (\RuntimeException) {
            // expected
        }

        // The invoice must remain a draft and no journal should exist.
        $this->assertTrue($invoice->fresh()->isDraft());
        $this->assertSame(0, JournalEntry::where('source_type', 'invoice')->where('source_id', $invoice->id)->count());
    }

    public function test_backfill_creates_journal_for_existing_invoice(): void
    {
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '1000', '3.30');
        // Simulate legacy data: delete the journal directly.
        DB::table('journal_entry_lines')->delete();
        DB::table('journal_entries')->delete();
        $this->assertSame(0, JournalEntry::count());

        $this->artisan('accounting:backfill')->assertSuccessful();

        $this->assertSame(1, JournalEntry::where('source_type', 'invoice')->where('source_id', $invoice->id)->count());
    }

    public function test_backfill_run_twice_creates_no_duplicates(): void
    {
        $this->makeIssuedInvoice($this->makeCustomer(), '1000', '3.30');
        $before = JournalEntry::count();

        $this->artisan('accounting:backfill')->assertSuccessful();
        $this->artisan('accounting:backfill')->assertSuccessful();

        $this->assertSame($before, JournalEntry::count());
    }
}
