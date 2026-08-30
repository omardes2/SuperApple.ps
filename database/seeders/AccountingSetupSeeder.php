<?php

namespace Database\Seeders;

use App\Enums\FinancialAccountType;
use App\Models\Account;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\LedgerPostingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Sets up the cash/bank accounts and expense categories, and posts opening
 * balances to the GL. DEMO figures only — not real balances. Idempotent by
 * name/code.
 */
class AccountingSetupSeeder extends Seeder
{
    public function run(): void
    {
        Auth::login(User::where('email', 'accountant@superapple.ps')->first() ?? User::first());
        $posting = app(LedgerPostingService::class);

        // Expense categories are seeded by the foundational ExpenseCategorySeeder
        // (production-safe); this demo seeder only adds cash/bank + opening balances.

        // ---- Financial accounts (cash / bank) with opening balances ----
        $accounts = [
            ['name' => 'الصندوق الرئيسي (شيكل)', 'type' => FinancialAccountType::Cash, 'currency' => 'ILS', 'gl' => '1110', 'opening' => '15000.00'],
            ['name' => 'صندوق الدولار (USD)', 'type' => FinancialAccountType::Cash, 'currency' => 'USD', 'gl' => '1120', 'opening' => '2000.00'],
            ['name' => 'البنك (شيكل)', 'type' => FinancialAccountType::Bank, 'currency' => 'ILS', 'gl' => '1130', 'opening' => '80000.00'],
        ];

        foreach ($accounts as $a) {
            $gl = Account::where('code', $a['gl'])->first();
            if ($gl === null) {
                continue;
            }
            $created = ! FinancialAccount::where('name', $a['name'])->exists();
            $fa = FinancialAccount::firstOrCreate(
                ['name' => $a['name']],
                [
                    'type' => $a['type'],
                    'currency' => $a['currency'],
                    'gl_account_id' => $gl->id,
                    'opening_balance' => $a['opening'],
                    'opening_balance_date' => now()->subMonths(2)->startOfMonth()->toDateString(),
                    'is_active' => true,
                    'created_by' => Auth::id(),
                ],
            );

            if ($created) {
                $posting->postOpeningBalance($fa);
            }
        }

        Auth::logout();
    }
}
