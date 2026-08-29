# Salaries & Adjustments (Sprint 6)

## Salary profiles (`employee_salary_profiles`)
Effective-dated base salary, kept **off** the employees table for
confidentiality. Fields: `employee_id`, `effective_from`, `effective_to?`,
`base_salary_ils`, `salary_type` (monthly|daily|hourly — monthly is the focus),
`working_days_basis?`, `daily_rate?`, `hourly_rate?`, `overtime_rate?`
(absolute ILS/hour), `status` (active|archived).

`SalaryProfileService::setSalary` creates a new profile and closes the previous
open one the day before — so salary **history** is preserved and a raise never
rewrites past payroll. A profile already used by an approved/posted payroll is
locked (create a new one instead).

## Adjustments (`salary_adjustments`)
One-time or recurring earnings/deductions:
- `adjustment_type`: **earning** (bonus, commission, allowance, …) or
  **deduction** (penalty, other, …).
- `amount_ils`, `effective_date`, `is_recurring`, `recurring_end_date?`.
- Deductions may carry a `gl_account_id`; unmapped deductions post to the default
  **Other Payroll Deductions (2500)** liability — never booked as profit.

Recurring adjustments appear in every payroll month within their window, counted
**once** per run. Manual adjustments can be added while a run is Draft/Calculated;
after approval the snapshot is frozen (edit → recalculate before approval, or
reverse/rebuild).

## How they enter payroll
`PayrollCalculator` buckets earnings into `allowances_ils` / `bonuses_ils` /
`commissions_ils` (added to gross) and sums deductions into `other_deductions_ils`
(withheld from net, credited to the mapped/default account). See PAYROLL.md.
