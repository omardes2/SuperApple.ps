# Expenses (Sprint 5)

An expense is money **paid now** for a cost (an unpaid purchase is a supplier
bill instead — see SUPPLIERS.md). Lifecycle via `ExpenseService`:
**draft → approved → posted (GL) → cancelled (reversal)**. Status is never set
by hand.

## Fields
`expense_number` (EXP-YYYY-####), `expense_date`, `category_id`, `supplier_id?`,
`project_id?`, `employee_id?`, `description`, `currency` (ILS|USD), `amount`
(original), `exchange_rate?`, `amount_ils` (accounting value), `payment_method`,
`financial_account_id`, `reference_number?`, `tax_amount?`, `status`,
`approved_by?`, `posted_at?`, cancellation fields.

## Currency
- **ILS**: `amount_ils = amount`.
- **USD**: `exchange_rate` required; `amount_ils = amount × rate`.

## Posting
Posting requires a cash/bank account whose **currency matches** the expense. The
journal is:
```
Dr <category expense account>   amount_ils
   Cr <financial account GL>        amount_ils
```
The expense GL account comes from `expense_categories.default_expense_account_id`
(falling back to `default_expense` / 5900). Input tax is **not** split in Sprint
5 (`tax_amount` is stored but the full amount posts to the expense account); the
model is ready for an input-tax account later.

A `project_id` is carried onto the journal lines as a reporting dimension (for
future project profitability).

## Immutability
Posted expenses are immutable (`Expense::LOCKED_FIELDS` +
`PostedRecordImmutableException`). Cancelling a posted expense posts a reversal
journal and sets status Cancelled. Draft/approved expenses are freely editable.

## Authorization
`expenses.view|create|edit|approve|post|cancel`, `expense_categories.manage` —
Accountant + GM only. UI at `/admin/expenses`.
