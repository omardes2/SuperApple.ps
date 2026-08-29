# Cash & Banks (Sprint 5)

`financial_accounts` are the cash boxes, bank accounts and cards. Each is backed
by a GL account (`gl_account_id`) and has a `currency` (ILS|USD).

## Derived balance (never stored)
An account's live balance is **derived** from posted journal lines tagged with
`financial_account_id` — `Σ debit_ils − Σ credit_ils`
(`FinancialAccountService::balanceIls`). `balanceOriginal` gives the balance in
the account's own currency. There is no editable `current_balance` field.

## Opening balances
Creating an account with an opening balance posts a journal
(`FinancialAccountService::create` → `postOpeningBalance`):
```
Dr <account GL>              opening (ILS value)
   Cr 3200 Opening Balance Equity   opening
```
USD accounts value the opening at the suggested rate on the opening date.

## Currency matching
Payments and expenses must use a financial account whose **currency matches** the
operation — no silent USD-into-ILS conversion. Depositing USD into an ILS account
would require a separate FX exchange transaction (out of scope in Sprint 5).

## Transfers (`account_transfers`)
Same-currency transfers only (`AccountTransferService`):
```
Dr <destination GL>   amount_ils
   Cr <source GL>          amount_ils
```
Cross-currency transfers are rejected.

## Reconciliation
The sum of financial-account derived balances equals the GL balance of the cash
& bank leaf accounts (Cash reconciliation report). UI at `/admin/cash-banks`
(cards per account, statement, create, transfer). Permissions
`financial_accounts.view|manage` — Accountant + GM only.
