<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

/**
 * Foundational, production-safe expense categories for a marketing / design
 * agency, each mapped to a real Expense account in the chart of accounts.
 *
 * Idempotent: accounts are matched by code and categories by name, so it never
 * duplicates and is safe to re-run. It seeds NO demo/financial data — only the
 * reference categories the expense workflow needs (without them, "+ مصروف"
 * cannot open a draft). A few dedicated leaf expense accounts that the base
 * chart did not include (internet, production, office supplies, maintenance,
 * hospitality, bank charges) are created here under the "المصاريف" (5000)
 * parent so every category has an accurate one-to-one GL mapping — never a
 * blind or wrong mapping. Fixed-asset purchases are intentionally excluded:
 * there is no Fixed Assets module, so buying equipment is not an expense
 * category.
 */
class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        $parent = Account::where('code', '5000')->first();

        // Dedicated leaf expense accounts missing from the base chart. Each is a
        // postable Expense leaf under 5000 المصاريف. [code, name]
        $newAccounts = [
            ['5310', 'إنترنت واتصالات'],
            ['5520', 'تصوير وإنتاج'],
            ['5810', 'مستلزمات مكتبية'],
            ['5820', 'صيانة'],
            ['5830', 'ضيافة'],
            ['5910', 'عمولات ومصاريف بنكية'],
        ];
        foreach ($newAccounts as [$code, $name]) {
            $account = Account::firstOrNew(['code' => $code]);
            if (! $account->exists) {
                $account->fill([
                    'name' => $name,
                    'account_type' => AccountType::Expense,
                    'normal_balance' => AccountType::Expense->normalBalance(),
                    'parent_id' => $parent?->id,
                    'is_system' => false,
                    'allow_manual_posting' => true,
                    'is_active' => true,
                ])->save();
            }
        }

        // Category → GL expense account (by code). Every account referenced here
        // is of type Expense.
        $categories = [
            ['name' => 'إيجار المكتب', 'code' => '5100'],
            ['name' => 'كهرباء ومياه', 'code' => '5300'],
            ['name' => 'إنترنت واتصالات', 'code' => '5310'],
            ['name' => 'برامج واشتراكات', 'code' => '5400'],
            ['name' => 'إعلانات وتسويق', 'code' => '5500'],
            ['name' => 'تصوير وإنتاج', 'code' => '5520'],
            ['name' => 'طباعة', 'code' => '5700'],
            ['name' => 'نقل ومواصلات', 'code' => '5600'],
            ['name' => 'مستلزمات مكتبية', 'code' => '5810'],
            ['name' => 'صيانة', 'code' => '5820'],
            ['name' => 'خدمات مهنية', 'code' => '5800'],
            ['name' => 'عمولات ومصاريف بنكية', 'code' => '5910'],
            ['name' => 'ضيافة', 'code' => '5830'],
            ['name' => 'مصاريف إدارية أخرى', 'code' => '5900'],
        ];

        foreach ($categories as $c) {
            $accountId = Account::where('code', $c['code'])->value('id');
            if ($accountId === null) {
                continue; // never create a category without a valid GL account
            }
            ExpenseCategory::firstOrCreate(
                ['name' => $c['name']],
                ['default_expense_account_id' => $accountId, 'is_active' => true],
            );
        }
    }
}
