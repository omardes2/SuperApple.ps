<?php

namespace Tests\Feature\Sprint5;

use App\Enums\RoleName;
use App\Models\JournalEntry;
use App\Services\PaymentService;
use App\Services\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class BackfillTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::Accountant));
    }

    private function wipeJournals(): void
    {
        DB::table('journal_entry_lines')->delete();
        DB::table('journal_entries')->delete();
    }

    private function legacyData(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '1000', '3.30');
        $payment = app(PaymentService::class)->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'USD', 'payment_amount' => 1000, 'exchange_rate' => '3.30',
        ]);
        app(PaymentService::class)->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => 1000]]);

        // A cancelled payment (history).
        $inv2 = $this->makeIssuedInvoice($customer, '500', '3.30');
        $p2 = app(PaymentService::class)->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'USD', 'payment_amount' => 500, 'exchange_rate' => '3.30',
        ]);
        app(PaymentService::class)->post($p2, [['invoice_id' => $inv2->id, 'allocated_usd' => 500]]);
        app(PaymentService::class)->cancel($p2->fresh(), auth()->user(), 'خطأ تاريخي');
    }

    public function test_backfill_supports_dry_run(): void
    {
        $this->legacyData();
        $this->wipeJournals();

        $this->artisan('accounting:backfill --dry-run')->assertSuccessful();
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->legacyData();
        $this->wipeJournals();

        $this->artisan('accounting:backfill --dry-run')->assertSuccessful();
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_backfill_posts_invoices(): void
    {
        $this->legacyData();
        $this->wipeJournals();

        $this->artisan('accounting:backfill')->assertSuccessful();
        $this->assertGreaterThan(0, JournalEntry::where('posting_type', 'invoice_issue')->count());
    }

    public function test_backfill_posts_payments(): void
    {
        $this->legacyData();
        $this->wipeJournals();

        $this->artisan('accounting:backfill')->assertSuccessful();
        $this->assertGreaterThan(0, JournalEntry::where('posting_type', 'payment_receipt')->count());
    }

    public function test_backfill_handles_cancelled_payment_history(): void
    {
        $this->legacyData();
        $this->wipeJournals();

        $this->artisan('accounting:backfill')->assertSuccessful();
        // The cancelled payment gets both a receipt and its reversal.
        $this->assertGreaterThan(0, JournalEntry::where('posting_type', 'payment_receipt_reversal')->count());
    }

    public function test_second_backfill_is_idempotent(): void
    {
        $this->legacyData();
        $this->wipeJournals();

        $this->artisan('accounting:backfill')->assertSuccessful();
        $count = JournalEntry::count();
        $this->artisan('accounting:backfill')->assertSuccessful();

        $this->assertSame($count, JournalEntry::count());
    }

    public function test_ar_reconciles_after_backfill(): void
    {
        $this->legacyData();
        $this->wipeJournals();
        $this->artisan('accounting:backfill')->assertSuccessful();

        $this->assertTrue(app(ReconciliationService::class)->accountsReceivable()['balanced']);
    }
}
