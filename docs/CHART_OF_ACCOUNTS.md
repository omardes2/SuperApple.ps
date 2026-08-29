# Chart of Accounts (Sprint 5)

The `chart_of_accounts` table is a tree of accounts. Postings only hit **active
leaf** accounts; parents group them for reporting. Each account has a `code`,
`account_type` (asset/liability/equity/revenue/expense), a `normal_balance`
(debit/credit derived from the type), `is_system`, `is_active` and
`allow_manual_posting`.

## Seeded accounts

| Code | Account | Type | System key |
|------|---------|------|------------|
| 1000 | الأصول (Assets) | asset | — (parent) |
| 1100 | النقد والبنوك (Cash & Banks) | asset | — (parent) |
| 1110 | الصندوق الرئيسي (شيكل) | asset | `default_cash_ils` |
| 1120 | صندوق الدولار (USD) | asset | `default_cash_usd` |
| 1130 | البنك (شيكل) | asset | — |
| 1200 | ذمم مدينة (Accounts Receivable) | asset | `accounts_receivable` |
| 1300 | مصاريف مدفوعة مقدماً (Prepaid) | asset | — |
| 1400 | سلف الموظفين (Employee Advances) | asset | `employee_advances_receivable` |
| 2000 | الالتزامات (Liabilities) | liability | — (parent) |
| 2100 | ذمم دائنة (Accounts Payable) | liability | `accounts_payable` |
| 2200 | ضريبة مستحقة (Tax Payable) | liability | `tax_payable` |
| 2300 | أرصدة العملاء الدائنة (Customer Credits) | liability | `customer_credits` |
| 2400 | رواتب مستحقة الدفع (Salary Payable) | liability | `salary_payable` |
| 2500 | استقطاعات رواتب أخرى (Other Payroll Deductions) | liability | `payroll_other_deductions` |
| 3000 | حقوق الملكية (Equity) | equity | — (parent) |
| 3100 | رأس مال المالك (Owner Equity) | equity | — |
| 3200 | حقوق ملكية - أرصدة افتتاحية | equity | `opening_balance_equity` |
| 4000 | الإيرادات (Revenue) | revenue | — (parent) |
| 4100 | إيرادات الخدمات (Service Revenue) | revenue | `service_revenue` |
| 4900 | أرباح فروقات الصرف (Exchange Gain) | revenue | `exchange_gain` |
| 5000 | المصاريف (Expenses) | expense | — (parent) |
| 5100 | مصروف إيجار (Rent) | expense | — |
| 5200 | مصروف رواتب (Salary Expense) | expense | `salary_expense` |
| 5300 | مرافق وخدمات (Utilities) | expense | — |
| 5400 | اشتراكات برمجية (Software) | expense | — |
| 5500 | مصروف إعلانات (Advertising) | expense | — |
| 5600 | مواصلات (Transportation) | expense | — |
| 5700 | مصروف طباعة (Printing) | expense | — |
| 5800 | خدمات مهنية (Professional) | expense | — |
| 5900 | مصاريف أخرى (Other) | expense | `default_expense` |
| 5950 | خسائر فروقات الصرف (Exchange Loss) | expense | `exchange_loss` |

Codes are re-mappable: business logic resolves accounts by the **system key**
via the `system_accounts` table (`SystemAccountKey` enum), never by code.

## Protection

- **System accounts** (`is_system`) cannot be deleted, and their type cannot be
  changed in a way that breaks historical journals; the name may be edited.
- An account **with any journal line** cannot be deleted — only deactivated.
- Posting to a **parent** account is rejected.
- Manual journals additionally require `allow_manual_posting = true`.

Managed at `/admin/accounting/chart` (permission `chart_accounts.view` /
`chart_accounts.manage`).
