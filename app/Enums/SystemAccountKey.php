<?php

namespace App\Enums;

/**
 * Stable business keys for the system chart-of-accounts entries. Business logic
 * resolves accounts by these keys (via the `system_accounts` map), never by a
 * hard-coded code, so the underlying account code can be re-mapped without
 * breaking posting logic or historical journals.
 */
enum SystemAccountKey: string
{
    case AccountsReceivable = 'accounts_receivable';
    case AccountsPayable = 'accounts_payable';
    case ServiceRevenue = 'service_revenue';
    case TaxPayable = 'tax_payable';
    case ExchangeGain = 'exchange_gain';
    case ExchangeLoss = 'exchange_loss';
    case CustomerCredits = 'customer_credits';
    case OpeningBalanceEquity = 'opening_balance_equity';
    case DefaultCashIls = 'default_cash_ils';
    case DefaultCashUsd = 'default_cash_usd';
    case DefaultExpense = 'default_expense';

    public function label(): string
    {
        return match ($this) {
            self::AccountsReceivable => 'ذمم مدينة (عملاء)',
            self::AccountsPayable => 'ذمم دائنة (موردون)',
            self::ServiceRevenue => 'إيرادات الخدمات',
            self::TaxPayable => 'ضريبة مستحقة',
            self::ExchangeGain => 'أرباح فروقات صرف',
            self::ExchangeLoss => 'خسائر فروقات صرف',
            self::CustomerCredits => 'أرصدة العملاء الدائنة',
            self::OpeningBalanceEquity => 'حقوق ملكية - أرصدة افتتاحية',
            self::DefaultCashIls => 'الصندوق الافتراضي (شيكل)',
            self::DefaultCashUsd => 'الصندوق الافتراضي (دولار)',
            self::DefaultExpense => 'مصاريف أخرى (افتراضي)',
        };
    }
}
