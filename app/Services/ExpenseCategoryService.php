<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\ExpenseCategory;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Create / update / (de)activate expense categories. A category always maps to
 * a valid, postable Expense account in the chart of accounts — never Cash,
 * Bank, Receivable, Payable, Revenue or Equity — so choosing a category at
 * expense time selects the correct GL account automatically and prevents
 * mis-classified postings. Categories are never hard-deleted (they carry
 * historical expenses); they are enabled/disabled instead.
 */
class ExpenseCategoryService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string,mixed>  $data
     */
    public function create(array $data): ExpenseCategory
    {
        $this->assertExpenseAccount($data['default_expense_account_id'] ?? null);

        $category = ExpenseCategory::create([
            'name' => $data['name'],
            'default_expense_account_id' => $data['default_expense_account_id'],
            'is_active' => $data['is_active'] ?? true,
        ]);

        $this->audit->log('expense_category_created', $category, 'Expenses', description: "إنشاء فئة مصروف: {$category->name}");

        return $category;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function update(ExpenseCategory $category, array $data): ExpenseCategory
    {
        $this->assertExpenseAccount($data['default_expense_account_id'] ?? $category->default_expense_account_id);

        $category->update([
            'name' => $data['name'] ?? $category->name,
            'default_expense_account_id' => $data['default_expense_account_id'] ?? $category->default_expense_account_id,
            'is_active' => $data['is_active'] ?? $category->is_active,
        ]);

        $this->audit->log('expense_category_updated', $category, 'Expenses', description: "تعديل فئة مصروف: {$category->name}");

        return $category;
    }

    public function setActive(ExpenseCategory $category, bool $active): ExpenseCategory
    {
        $category->update(['is_active' => $active]);
        $this->audit->log($active ? 'expense_category_activated' : 'expense_category_deactivated', $category, 'Expenses',
            description: ($active ? 'تفعيل' : 'تعطيل')." فئة مصروف: {$category->name}");

        return $category;
    }

    /**
     * Guard: only a postable (active, leaf) Expense account may back a category.
     */
    private function assertExpenseAccount(int|string|null $accountId): void
    {
        $account = $accountId ? Account::find($accountId) : null;

        if ($account === null || $account->account_type !== AccountType::Expense) {
            throw new RuntimeException('يجب ربط الفئة بحساب مصروفات صالح من دليل الحسابات.');
        }
        if (! $account->canReceivePosting()) {
            throw new RuntimeException('الحساب المحاسبي المختار غير قابل للترحيل (حساب رئيسي أو غير مفعّل).');
        }
    }

    /**
     * Expense accounts eligible to back a category: postable (active, leaf)
     * accounts of type Expense, ordered by code.
     *
     * @return Collection<int,Account>
     */
    public function eligibleAccounts()
    {
        return Account::query()
            ->where('account_type', AccountType::Expense->value)
            ->postable()
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }
}
