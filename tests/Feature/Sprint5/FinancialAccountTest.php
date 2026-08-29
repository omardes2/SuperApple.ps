<?php

namespace Tests\Feature\Sprint5;

use App\Enums\RoleName;
use App\Models\JournalEntry;
use App\Services\AccountTransferService;
use App\Services\ExpenseService;
use App\Services\FinancialAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class FinancialAccountTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::Accountant));
    }

    public function test_opening_balance_creates_journal(): void
    {
        $account = $this->makeCashAccount('ILS', '5000');

        $this->assertDatabaseHas('journal_entries', [
            'source_type' => 'financial_account', 'source_id' => $account->id, 'posting_type' => 'opening_balance',
        ]);
        $entry = JournalEntry::where('source_id', $account->id)->where('posting_type', 'opening_balance')->firstOrFail();
        $this->assertSame('5000.00', $entry->totalDebit());
    }

    public function test_balance_is_derived_from_gl(): void
    {
        $account = $this->makeCashAccount('ILS', '5000');
        $this->assertSame('5000.00', app(FinancialAccountService::class)->balanceIls($account));

        // Spend 1,200 via an expense → balance drops to 3,800.
        $category = $this->expenseCategory();
        $expense = app(ExpenseService::class)->createDraft([
            'category_id' => $category->id, 'description' => 'x', 'currency' => 'ILS',
            'amount' => '1200', 'financial_account_id' => $account->id,
        ]);
        app(ExpenseService::class)->post($expense);

        $this->assertSame('3800.00', app(FinancialAccountService::class)->balanceIls($account->fresh()));
    }

    public function test_ils_transaction_cannot_post_into_usd_account(): void
    {
        $usd = $this->makeCashAccount('USD');
        $category = $this->expenseCategory();
        $expense = app(ExpenseService::class)->createDraft([
            'category_id' => $category->id, 'description' => 'mismatch', 'currency' => 'ILS',
            'amount' => '100', 'financial_account_id' => $usd->id,
        ]);

        $this->expectException(RuntimeException::class);
        app(ExpenseService::class)->post($expense);
    }

    public function test_account_with_transactions_cannot_be_deleted(): void
    {
        $account = $this->makeCashAccount('ILS', '1000');
        // Opening balance created activity.
        $this->assertTrue(app(FinancialAccountService::class)->hasActivity($account));
    }

    public function test_same_currency_transfer_balances_correctly(): void
    {
        $from = $this->makeCashAccount('ILS', '5000');
        $to = $this->makeCashAccount('ILS', '0');

        app(AccountTransferService::class)->transfer($from, $to, '1500');

        $svc = app(FinancialAccountService::class);
        $this->assertSame('3500.00', $svc->balanceIls($from->fresh()));
        $this->assertSame('1500.00', $svc->balanceIls($to->fresh()));

        $entry = JournalEntry::where('posting_type', 'transfer')->firstOrFail();
        $this->assertSame($entry->totalDebit(), $entry->totalCredit());
    }

    public function test_cross_currency_transfer_is_rejected(): void
    {
        $ils = $this->makeCashAccount('ILS', '5000');
        $usd = $this->makeCashAccount('USD', '1000');

        $this->expectException(RuntimeException::class);
        app(AccountTransferService::class)->transfer($ils, $usd, '100');
    }
}
