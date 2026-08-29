# Payroll (Sprint 6)

Payroll links the HR data from Sprint 1 (employees, attendance, leaves) to the
double-entry accounting from Sprint 5. Everything is in **ILS** via brick/math.

## Confidentiality
Salary data is **separate** from the operational employee profile. `base_salary`
is **not** on the `employees` table — it lives in `employee_salary_profiles`.
A regular employee never sees base salary, history, bonuses, deductions,
advances, employer cost, payroll runs, payroll accounting, or anyone else's pay.
The operational employee experience has **no** salary tab; an employee may only
open **their own payslip** (`payslips.view_own`).

## Roles (separation of duties)
- **HR Manager** — salary profiles, adjustments, advances, payroll create /
  calculate / approve, payslip visibility. **No GL / journals / posting.**
- **Accountant** — payroll post (GL), salary payments, reversal, reconciliation,
  advance payment. **No HR salary management.**
- **General Manager / Super Admin** — everything.
- **Employee** — `payslips.view_own` only.

## Salary profiles (effective-dated)
`employee_salary_profiles`: `effective_from`/`effective_to`, `base_salary_ils`,
`salary_type` (monthly focus), `overtime_rate` (absolute ILS/hour, optional).
The salary used for a month is the profile whose window covers that month; a
raise creates a **new** profile (closing the previous one) — historical payrolls
never change. A profile used by an approved/posted payroll cannot be edited.

## Payroll run workflow
`draft → calculated → approved → posted (GL) → paid`, one run per month
(`PAYROLL-YYYY-MM`, unique `year+month`). `PayrollService` owns it:
- **calculate** — (re)builds a `payroll_items` snapshot for every active
  employee with a salary profile. Draft/Calculated only.
- **approve** — freezes the snapshot.
- **post** — commits advance recoveries + posts the GL accrual atomically.
- **pay** — salary payments (partial supported) via `PayrollPaymentService`.
- **reverse** — posted runs are immutable; corrections are a reversal
  (blocked while posted salary payments exist).

## Calculation (`PayrollCalculator`)
For each employee over the run's month (all reads, never mutates source):
- **Working days** = weekdays in `attendance.work_days`, on/after hire date.
- Each working day is **attended** (attendance present/late/remote/external),
  **paid leave** / **unpaid leave** (`LeaveType.is_paid`), or **absent**.
- `daily_rate = base ÷ payroll.salary_divisor` (default 30, a setting — never
  hard-coded).
- **Absence** deduction = `absent_days × daily_rate` (if enabled).
- **Unpaid leave** deduction = `unpaid_leave_days × daily_rate`. Paid leave never
  deducts.
- **Late** deduction (off by default) = `late_minutes × minute_rate`, where
  `minute_rate = daily_rate ÷ working_hours ÷ 60`. Uses attendance
  `late_minutes` as-is (grace already applied by AttendanceService — never
  re-applied).
- **Overtime** = `overtime_hours × rate`, rate = the profile's absolute overtime
  rate, else `hourly_rate × payroll.default_overtime_multiplier` (1.5). Zero when
  no overtime.
- **Adjustments** (`salary_adjustments`): earnings (bonus/commission/allowance)
  and deductions (penalty/other). Recurring rows apply once per month within
  their window.
- **Net** = gross earnings − (absence + late + unpaid-leave + other deductions +
  advance recovery). **Never negative** (`payroll_allow_negative_net_salary =
  false`): deductions are capped, and any un-recovered advance carries forward.

Every figure is explained in `calculation_snapshot` (e.g. `absence: {days,
daily_rate, amount}`).

## Snapshots
After calculate, each `payroll_items` row holds frozen snapshots (employee name,
department, job title, base salary, attendance). Later changes to attendance,
salary or department do **not** alter an approved payroll — the snapshot is the
historical protection (attendance itself is not locked).

## Accounting (see ACCOUNTING.md for the journal examples)
- **Accrual (post)**: `Dr Salary Expense (5200)` = earned (gross − pay
  reductions); `Cr Employee Advances (1400)` = recovered advances; `Cr` other
  withholding accounts; `Cr Salary Payable (2400)` = net. Balances by
  construction; source-idempotent; atomic (a GL failure rolls back the whole
  post, recoveries included).
- **Salary payment**: `Dr Salary Payable / Cr Cash/Bank`. Partial supported; the
  run becomes **Paid** only when every item's remaining is 0.
- **Reversal**: `AccountingService.reverse` mirrors the journal; advance
  recoveries are restored; posted salary payments must be reversed first.

## Reports & reconciliation
Payroll flows into the standard GL / Trial Balance / P&L / Balance Sheet with no
duplicate reporting logic. Dedicated payroll reports: summary by department,
outstanding salary payables, employee advances, salary payments
(`/admin/payroll/reports`). Two reconciliations (in the Reconciliation report):
- **Salary Payable** GL (2400) == Σ posted items' unpaid remaining.
- **Employee Advances** GL (1400) == Σ outstanding advances.

## Settings (`payroll` group)
`salary_divisor` (30), `default_overtime_multiplier` (1.5),
`late_deduction_enabled` (false), `absence_deduction_enabled` (true),
`pay_day`, `allow_negative_net_salary` (false).

## Out of scope (Sprint 7+)
No subscriptions/recurring invoices, no WhatsApp reminders. Payroll currency is
ILS only.
