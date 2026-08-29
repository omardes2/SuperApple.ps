<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\SystemAccountKey;
use App\Models\Account;
use App\Models\SystemAccount;
use Illuminate\Database\Seeder;

/**
 * Seeds the base chart of accounts and maps the stable system-account keys.
 * Idempotent: accounts are matched by code, so re-running never duplicates.
 * Account codes may be re-numbered later; business logic uses the keys, not
 * the codes.
 */
class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        // [code, name, type, parent_code|null, is_system, allow_manual_posting]
        $accounts = [
            ['1000', 'الأصول', AccountType::Asset, null, true, false],
            ['1100', 'النقد والبنوك', AccountType::Asset, '1000', true, false],
            ['1110', 'الصندوق الرئيسي (شيكل)', AccountType::Asset, '1100', true, false],
            ['1120', 'صندوق الدولار (USD)', AccountType::Asset, '1100', true, false],
            ['1130', 'البنك (شيكل)', AccountType::Asset, '1100', false, false],
            ['1200', 'ذمم مدينة (عملاء)', AccountType::Asset, '1000', true, false],
            ['1300', 'مصاريف مدفوعة مقدماً', AccountType::Asset, '1000', true, true],
            ['1400', 'سلف الموظفين (مدينة)', AccountType::Asset, '1000', true, false],

            ['2000', 'الالتزامات', AccountType::Liability, null, true, false],
            ['2100', 'ذمم دائنة (موردون)', AccountType::Liability, '2000', true, false],
            ['2200', 'ضريبة مستحقة', AccountType::Liability, '2000', true, false],
            ['2300', 'أرصدة العملاء الدائنة', AccountType::Liability, '2000', true, false],
            ['2400', 'رواتب مستحقة الدفع', AccountType::Liability, '2000', true, false],
            ['2500', 'استقطاعات رواتب أخرى', AccountType::Liability, '2000', true, false],

            ['3000', 'حقوق الملكية', AccountType::Equity, null, true, false],
            ['3100', 'رأس مال المالك', AccountType::Equity, '3000', true, true],
            ['3200', 'حقوق ملكية - أرصدة افتتاحية', AccountType::Equity, '3000', true, false],

            ['4000', 'الإيرادات', AccountType::Revenue, null, true, false],
            ['4100', 'إيرادات الخدمات', AccountType::Revenue, '4000', true, false],
            ['4900', 'أرباح فروقات الصرف', AccountType::Revenue, '4000', true, false],

            ['5000', 'المصاريف', AccountType::Expense, null, true, false],
            ['5100', 'مصروف إيجار', AccountType::Expense, '5000', false, true],
            ['5200', 'مصروف رواتب', AccountType::Expense, '5000', false, true], // prepared; no payroll posting in Sprint 5
            ['5300', 'مرافق وخدمات', AccountType::Expense, '5000', false, true],
            ['5400', 'اشتراكات برمجية', AccountType::Expense, '5000', false, true],
            ['5500', 'مصروف إعلانات', AccountType::Expense, '5000', false, true],
            ['5600', 'مواصلات', AccountType::Expense, '5000', false, true],
            ['5700', 'مصروف طباعة', AccountType::Expense, '5000', false, true],
            ['5800', 'خدمات مهنية', AccountType::Expense, '5000', false, true],
            ['5900', 'مصاريف أخرى', AccountType::Expense, '5000', true, true],
            ['5950', 'خسائر فروقات الصرف', AccountType::Expense, '5000', true, false],
        ];

        $byCode = [];
        foreach ($accounts as [$code, $name, $type, $parentCode, $isSystem, $allowManual]) {
            $account = Account::firstOrNew(['code' => $code]);
            $account->fill([
                'name' => $name,
                'account_type' => $type,
                'normal_balance' => $type->normalBalance(),
                'parent_id' => $parentCode ? ($byCode[$parentCode]->id ?? null) : null,
                'is_system' => $isSystem,
                'allow_manual_posting' => $allowManual,
                'is_active' => true,
            ]);
            $account->save();
            $byCode[$code] = $account;
        }

        // Map system keys → codes.
        $map = [
            SystemAccountKey::AccountsReceivable->value => '1200',
            SystemAccountKey::AccountsPayable->value => '2100',
            SystemAccountKey::TaxPayable->value => '2200',
            SystemAccountKey::CustomerCredits->value => '2300',
            SystemAccountKey::OpeningBalanceEquity->value => '3200',
            SystemAccountKey::ServiceRevenue->value => '4100',
            SystemAccountKey::ExchangeGain->value => '4900',
            SystemAccountKey::ExchangeLoss->value => '5950',
            SystemAccountKey::DefaultCashIls->value => '1110',
            SystemAccountKey::DefaultCashUsd->value => '1120',
            SystemAccountKey::DefaultExpense->value => '5900',
            SystemAccountKey::EmployeeAdvancesReceivable->value => '1400',
            SystemAccountKey::SalaryPayable->value => '2400',
            SystemAccountKey::SalaryExpense->value => '5200',
            SystemAccountKey::PayrollOtherDeductions->value => '2500',
        ];

        foreach ($map as $key => $code) {
            SystemAccount::updateOrCreate(
                ['key' => $key],
                ['account_id' => $byCode[$code]->id],
            );
        }
    }
}
