<?php

namespace Tests\Feature\Sprint5;

use App\Enums\RoleName;
use App\Exceptions\PostedRecordImmutableException;
use App\Models\Account;
use App\Models\ExpenseCategory;
use App\Models\JournalEntry;
use App\Services\ExpenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class ExpenseAccountingTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::Accountant));
    }

    private function service(): ExpenseService
    {
        return app(ExpenseService::class);
    }

    private function rentCategory(): ExpenseCategory
    {
        return ExpenseCategory::firstOrCreate(
            ['name' => 'إيجار'],
            ['default_expense_account_id' => Account::where('code', '5100')->value('id'), 'is_active' => true],
        );
    }

    private function journal(int $expenseId): JournalEntry
    {
        return JournalEntry::with('lines')->where('source_type', 'expense')
            ->where('source_id', $expenseId)->where('posting_type', 'expense')->firstOrFail();
    }

    public function test_ils_expense_posts_correct_debit_and_credit(): void
    {
        $cash = $this->makeCashAccount('ILS');
        $expense = $this->service()->createDraft([
            'category_id' => $this->rentCategory()->id, 'description' => 'إيجار',
            'currency' => 'ILS', 'amount' => '3000', 'financial_account_id' => $cash->id,
        ]);
        $this->service()->post($expense);

        $entry = $this->journal($expense->id);
        $rent = $entry->lines->firstWhere('account_id', Account::where('code', '5100')->value('id'));
        $this->assertSame('3000.00', $rent->debit_ils);
        $cashLine = $entry->lines->firstWhere('financial_account_id', $cash->id);
        $this->assertSame('3000.00', $cashLine->credit_ils);
    }

    public function test_usd_expense_conversion_is_correct(): void
    {
        $cash = $this->makeCashAccount('USD');
        $expense = $this->service()->createDraft([
            'category_id' => $this->rentCategory()->id, 'description' => 'Adobe',
            'currency' => 'USD', 'amount' => '600', 'exchange_rate' => '3.30', 'financial_account_id' => $cash->id,
        ]);
        $this->service()->post($expense);

        $this->assertSame('1980.00', $expense->fresh()->amount_ils); // 600 × 3.30
        $entry = $this->journal($expense->id);
        $this->assertSame('1980.00', $entry->totalDebit());
    }

    public function test_expense_uses_category_gl_account(): void
    {
        $cash = $this->makeCashAccount('ILS');
        $category = ExpenseCategory::firstOrCreate(
            ['name' => 'مرافق'],
            ['default_expense_account_id' => Account::where('code', '5300')->value('id'), 'is_active' => true],
        );
        $expense = $this->service()->createDraft([
            'category_id' => $category->id, 'description' => 'كهرباء',
            'currency' => 'ILS', 'amount' => '400', 'financial_account_id' => $cash->id,
        ]);
        $this->service()->post($expense);

        $entry = $this->journal($expense->id);
        $this->assertNotNull($entry->lines->firstWhere('account_id', Account::where('code', '5300')->value('id')));
    }

    public function test_expense_requires_financial_account_to_post(): void
    {
        $expense = $this->service()->createDraft([
            'category_id' => $this->rentCategory()->id, 'description' => 'بدون حساب',
            'currency' => 'ILS', 'amount' => '100',
        ]);

        $this->expectException(RuntimeException::class);
        $this->service()->post($expense);
    }

    public function test_posted_expense_is_immutable(): void
    {
        $cash = $this->makeCashAccount('ILS');
        $expense = $this->service()->createDraft([
            'category_id' => $this->rentCategory()->id, 'description' => 'إيجار',
            'currency' => 'ILS', 'amount' => '500', 'financial_account_id' => $cash->id,
        ]);
        $this->service()->post($expense);

        $this->expectException(PostedRecordImmutableException::class);
        $expense->fresh()->update(['amount' => '9999']);
    }

    public function test_expense_cancel_creates_reversal(): void
    {
        $cash = $this->makeCashAccount('ILS');
        $expense = $this->service()->createDraft([
            'category_id' => $this->rentCategory()->id, 'description' => 'إيجار',
            'currency' => 'ILS', 'amount' => '500', 'financial_account_id' => $cash->id,
        ]);
        $this->service()->post($expense);
        $this->service()->cancel($expense->fresh(), $this->makeUser(RoleName::Accountant), 'خطأ');

        $this->assertSame('cancelled', $expense->fresh()->status->value);
        $this->assertSame('reversed', $this->journal($expense->id)->fresh()->status->value);
        $this->assertDatabaseHas('journal_entries', ['posting_type' => 'expense_reversal', 'source_id' => $expense->id]);
    }

    public function test_project_linked_expense_carries_project_dimension(): void
    {
        $cash = $this->makeCashAccount('ILS');
        $project = $this->makeProject();
        $expense = $this->service()->createDraft([
            'category_id' => $this->rentCategory()->id, 'description' => 'مصروف مشروع',
            'currency' => 'ILS', 'amount' => '250', 'financial_account_id' => $cash->id, 'project_id' => $project->id,
        ]);
        $this->service()->post($expense);

        $entry = $this->journal($expense->id);
        $this->assertTrue($entry->lines->pluck('project_id')->contains($project->id));
    }
}
