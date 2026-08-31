<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Enums\SystemAccountKey;
use App\Models\CustomerOpeningBalance;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\AccountingService;
use App\Services\CustomerOpeningBalanceService;
use App\Services\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class OpeningBalanceCoreTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::Accountant));
    }

    private function service(): CustomerOpeningBalanceService
    {
        return app(CustomerOpeningBalanceService::class);
    }

    public function test_debit_opening_balance_posts_ar_debit_and_obe_credit(): void
    {
        $customer = $this->makeCustomer(['whatsapp_number' => '0599432037']);
        $ob = $this->service()->create($customer, [
            'type' => 'debit', 'amount_usd' => '1000', 'exchange_rate' => '3.10', 'balance_date' => '2026-01-01',
        ]);

        $this->assertSame('3100.00', $ob->amount_ils);
        $this->assertSame('1000.00', $ob->remaining_usd);

        $entry = JournalEntry::with('lines')->whereKey($ob->journal_entry_id)->firstOrFail();
        $arId = app(AccountingService::class)->systemAccountId(SystemAccountKey::AccountsReceivable);
        $obeId = app(AccountingService::class)->systemAccountId(SystemAccountKey::OpeningBalanceEquity);

        $ar = $entry->lines->firstWhere('account_id', $arId);
        $obe = $entry->lines->firstWhere('account_id', $obeId);
        $this->assertSame('3100.00', $ar->debit_ils);
        $this->assertSame('3100.00', $obe->credit_ils);
        // Balanced.
        $this->assertSame((float) $entry->lines->sum('debit_ils'), (float) $entry->lines->sum('credit_ils'));
    }

    public function test_credit_opening_balance_is_mirrored(): void
    {
        $customer = $this->makeCustomer(['whatsapp_number' => '0599432037']);
        $ob = $this->service()->create($customer, [
            'type' => 'credit', 'amount_usd' => '500', 'exchange_rate' => '3.10', 'balance_date' => '2026-01-01',
        ]);

        $entry = JournalEntry::with('lines')->whereKey($ob->journal_entry_id)->firstOrFail();
        $arId = app(AccountingService::class)->systemAccountId(SystemAccountKey::AccountsReceivable);
        $ar = $entry->lines->firstWhere('account_id', $arId);
        $this->assertSame('1550.00', $ar->credit_ils); // AR credited (in customer's favour)
        $this->assertSame('0.00', $ob->remaining_usd);
    }

    public function test_opening_balance_does_not_create_revenue(): void
    {
        $customer = $this->makeCustomer(['whatsapp_number' => '0599432037']);
        $this->service()->create($customer, [
            'type' => 'debit', 'amount_usd' => '1000', 'exchange_rate' => '3.10', 'balance_date' => '2026-01-01',
        ]);

        $revenueId = app(AccountingService::class)->systemAccountId(SystemAccountKey::ServiceRevenue);
        $this->assertSame(0, JournalEntryLine::where('account_id', $revenueId)->count());
    }

    public function test_reconciliation_stays_balanced_after_opening_balance(): void
    {
        $customer = $this->makeCustomer(['whatsapp_number' => '0599432037']);
        $this->service()->create($customer, [
            'type' => 'debit', 'amount_usd' => '1000', 'exchange_rate' => '3.10', 'balance_date' => '2026-01-01',
        ]);

        $ar = app(ReconciliationService::class)->accountsReceivable();
        $this->assertTrue($ar['balanced'], 'AR must reconcile after an opening balance');
    }

    public function test_only_one_posted_opening_balance_per_customer(): void
    {
        $customer = $this->makeCustomer(['whatsapp_number' => '0599432037']);
        $this->service()->create($customer, [
            'type' => 'debit', 'amount_usd' => '1000', 'exchange_rate' => '3.10', 'balance_date' => '2026-01-01',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->service()->create($customer, [
            'type' => 'debit', 'amount_usd' => '500', 'exchange_rate' => '3.10', 'balance_date' => '2026-01-01',
        ]);
    }

    public function test_posted_opening_balance_cannot_be_edited_directly(): void
    {
        $customer = $this->makeCustomer(['whatsapp_number' => '0599432037']);
        $ob = $this->service()->create($customer, [
            'type' => 'debit', 'amount_usd' => '1000', 'exchange_rate' => '3.10', 'balance_date' => '2026-01-01',
        ]);

        // Reverse creates a mirror journal and keeps history, never a delete.
        $this->service()->reverse($ob->fresh(), $this->makeUser(RoleName::Accountant), 'خطأ إدخال');
        $this->assertSame(CustomerOpeningBalance::STATUS_REVERSED, $ob->fresh()->status);
        $this->assertDatabaseHas('customer_opening_balances', ['id' => $ob->id]); // never hard-deleted
        // AR back to balanced after reversal.
        $this->assertTrue(app(ReconciliationService::class)->accountsReceivable()['balanced']);
    }
}
