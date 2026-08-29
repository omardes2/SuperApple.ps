# Employee Advances & Loans (Sprint 6)

An **advance** is money paid to an employee ahead of salary and recovered from
later payrolls. A **loan** is the same structure with an installment plan
(interest-free in Sprint 6). Both are `employee_advances` rows distinguished by
`type` (advance|loan). Advances are ILS only.

## Fields
`advance_number` (ADV-YYYY-####), `employee_id`, `type`, `request_date`,
`approved_date?`, `amount_ils`, `remaining_ils`, `installment_ils?` (per-payroll
recovery), `installments?` (loan), `status`, `financial_account_id?`, `paid_at?`.

## Lifecycle (`EmployeeAdvanceService`)
`draft → approved → paid → partially_recovered → recovered` (or `cancelled`).
- **pay** requires a cash/bank account. It is **not** a salary expense:
  ```
  Dr 1400 Employee Advances Receivable   amount
     Cr <cash/bank>                          amount
  ```
- **recovery** happens during payroll posting: `PayrollCalculator` plans a
  recovery (installment, else remaining) **capped so net ≥ 0** — any excess
  carries to the next month. At post, `PayrollService` commits the recovery
  (reducing `remaining_ils`, recording an `employee_advance_recoveries` row) and
  the payroll journal credits `1400 Employee Advances` for the recovered amount.
- **reversal**: reversing a payroll restores the advance's remaining and marks
  recoveries reversed. Cancelling a **paid** (not-yet-recovered) advance reverses
  its payment journal; a partially/fully recovered advance cannot be cancelled.

## Reconciliation
GL **Employee Advances Receivable (1400)** debit balance == Σ outstanding
advances' `remaining_ils` (paid / partially_recovered). Shown in the
Reconciliation report and verified by `AdvanceTest` / `PayrollReconciliationTest`.
