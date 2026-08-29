# Double-Entry Accounting (Sprint 5)

Sprint 5 is the real accounting foundation. Every financial event now posts a
balanced GL journal. **The General Ledger is the single source of truth**;
sub-ledger figures (customer balances, supplier balances, cash balances) are
derived and must reconcile back to it.

## Fixed rules

- **Base ledger currency = ILS.** Every journal line carries `debit_ils` /
  `credit_ils`. When the source operation is in USD, the line also keeps the
  `original_currency`, `original_amount` and `exchange_rate`.
- **Invoices stay USD** (official customer receivable). At issue, the ILS
  accounting entry uses the invoice's frozen `exchange_rate` snapshot.
- **Double entry**: every posted journal has `Σ debit_ils == Σ credit_ils`
  exactly (HALF_UP, 2dp). An unbalanced journal is rejected.
- **Posted journals are immutable.** Corrections are made by a **reversal**
  journal (debits/credits swapped) + a new journal — never by editing.
- **Source idempotency**: at most one journal per `(source_type, source_id,
  posting_type)` (DB unique index + a service guard). Manual journals have a
  null source, so many are allowed.
- **Postings only hit active leaf accounts** (never a parent); manual journals
  additionally require `allow_manual_posting`.
- Exchange differences are **realised gain/loss**, never sales revenue.

## Engine

`AccountingService` is the only place journals are created:
`post(header, lines)` (validates balance + accounts, assigns `JRN-YYYY-######`,
writes atomically), `reverse(entry)` (mirror entry + marks original reversed),
`systemAccount(key)`, `hasPosted(...)`. `LedgerPostingService` builds the
correct lines for each document type. Business services
(`InvoiceService`, `PaymentService`, `ExpenseService`,
`SupplierBillService`, `SupplierPaymentService`,
`FinancialAccountService`, `AccountTransferService`) call it inside their own
transaction, so a GL failure rolls the whole operation back (an invoice can
never be Issued without its journal).

A reversed entry stays in the ledger — it is offset by its reversal (both are
real postings that net to zero). GL balance queries therefore include
`posted` **and** `reversed` status, excluding only `draft`.

## System accounts

Business logic resolves accounts by a stable **key** (`SystemAccountKey` →
`system_accounts` map), never by code, so codes can be re-numbered safely:
`accounts_receivable`, `accounts_payable`, `service_revenue`, `tax_payable`,
`exchange_gain`, `exchange_loss`, `customer_credits`,
`opening_balance_equity`, `default_cash_ils`, `default_cash_usd`,
`default_expense`. See CHART_OF_ACCOUNTS.md.

## Journal examples (all amounts ILS)

### 1. Invoice issue — $2,000 @ 3.28
```
Dr 1200 Accounts Receivable   6,560.00
   Cr 4100 Service Revenue        6,560.00
(original: 2,000 USD @ 3.28; dimensions: customer, invoice)
```

### 2. Invoice with tax — subtotal $1,000, tax 17% ($170), total $1,170 @ 3.30
```
Dr 1200 Accounts Receivable   3,861.00
   Cr 4100 Service Revenue        3,300.00   (taxable, after discount)
   Cr 2200 Tax Payable              561.00
```
Revenue is the taxable amount after discounts; revenue is the balancing plug so
the entry ties exactly to AR.

### 3. Customer payment — ILS, gain. Invoice $1,000 @ 3.20 (AR 3,200), pay 3,300 ILS @ 3.30
```
Dr 1110 Cash (ILS)            3,300.00
   Cr 1200 Accounts Receivable    3,200.00
   Cr 4900 Exchange Gain            100.00
```

### 4. Customer payment — ILS, loss. Invoice $1,000 @ 3.30 (AR 3,300), pay 3,200 ILS @ 3.20
```
Dr 1110 Cash (ILS)            3,200.00
Dr 5950 Exchange Loss           100.00
   Cr 1200 Accounts Receivable    3,300.00
```

### 5. Customer payment — USD. Invoice $1,000 @ 3.20 (AR 3,200), pay $1,000 @ 3.30
```
Dr 1120 USD Cash             3,300.00   (accounting value; original 1,000 USD @ 3.30)
   Cr 1200 Accounts Receivable    3,200.00
   Cr 4900 Exchange Gain            100.00
```

### 6. Unallocated customer credit — pay $2,000, allocate $1,500 @ 3.30
```
Dr 1120 USD Cash             6,600.00
   Cr 1200 Accounts Receivable    4,950.00
   Cr 2300 Customer Credits       1,650.00   (the $500 overpayment)
```
The overpayment is a **liability** (customer advance), never revenue.

### 7. Expense — paid rent 3,000 ILS
```
Dr 5100 Rent Expense         3,000.00
   Cr 1130 Bank (ILS)             3,000.00
```

### 8. Supplier bill (accrual) — printing 1,000 ILS, unpaid
```
Dr 5700 Printing Expense     1,000.00
   Cr 2100 Accounts Payable       1,000.00
```

### 9. Supplier payment — USD bill $300 @ 3.30 (AP 990), pay $300 @ 3.20 → gain
```
Dr 2100 Accounts Payable       990.00
   Cr 1120 USD Cash               960.00
   Cr 4900 Exchange Gain           30.00
```

### 10. Reversal (any document cancelled)
The original journal is kept and marked `reversed`; a mirror journal is posted
with debits/credits swapped and `posting_type = <original>_reversal`, netting
the effect to zero while preserving history.

## Backfill

`php artisan accounting:backfill [--dry-run]` creates journals for historical
invoices and payments issued before the GL existed. It **never re-runs business
actions** — it only posts journals from the snapshots already on the documents,
is idempotent (skips anything already posted), reverses cancelled invoices, and
reconstructs + reverses cancelled payments. `--dry-run` reports counts and
writes nothing.

## Reconciliation

`ReconciliationService` ties each control account back to its sub-ledger:
AR GL == Σ invoice remaining × invoice rate; AP GL == Σ bill remaining × bill
rate; Cash GL == Σ financial-account derived balances. See the Reconciliation
report and `ReportsTest`.

## Reports

`AccountingReportService`: Trial Balance (debits == credits), General Ledger
(running balance per account), Profit & Loss (revenue − expenses, exchange
gain/loss as their own lines), Balance Sheet (Assets = Liabilities + Equity,
with period retained earnings folded into equity).

## Out of scope (Sprint 6+)

No payroll posting / salary payable / employee loans, no WhatsApp reminders, no
recurring-subscription accounting, no advanced VAT filing, no inventory. The
`5200 Salary Expense` account exists but nothing posts to it yet.
