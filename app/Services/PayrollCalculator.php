<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;

/**
 * Computes one employee's payroll figures for a run — all in ILS via brick/math.
 * Pure calculation: it reads salary profile, attendance and leaves (never
 * mutates them) and returns a full breakdown plus a snapshot explaining every
 * figure. Advance recovery is returned as a plan; the service commits it at post.
 *
 * Accounting model:
 *   grossEarnings   = base + overtime + allowances + bonuses + commissions
 *   payReductions   = absence + late + unpaid-leave   (reduce salary expense)
 *   otherWithheld   = other adjustment deductions      (credited to their account)
 *   advanceRecovery = capped so net >= 0               (credited to Advances)
 *   net             = grossEarnings − (payReductions + otherWithheld + advanceRecovery)
 */
class PayrollCalculator
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @return array<string,mixed>|null null when the employee has no salary profile for the period
     */
    public function calculate(Employee $employee, PayrollRun $run): ?array
    {
        $periodStart = $run->period_start->toDateString();
        $periodEnd = $run->period_end->toDateString();

        $profile = $employee->salaryProfileOn($periodStart) ?? $employee->salaryProfileOn($periodEnd);
        if ($profile === null) {
            return null;
        }

        $base = Money::money($profile->base_salary_ils);
        $divisor = max(1, (int) $this->settings->get('payroll', 'salary_divisor', 30));
        $workingHours = (float) ($employee->working_hours_per_day ?: $this->settings->get('attendance', 'default_working_hours', 8));
        $workingHours = $workingHours > 0 ? $workingHours : 8;

        $att = $this->attendanceBreakdown($employee, $periodStart, $periodEnd);

        // ---- Rates ----
        $dailyRate = Money::money(Money::of($base)->dividedBy($divisor, 8, RoundingMode::HalfUp));
        $minuteRate = Money::money(Money::of($dailyRate)->dividedBy($workingHours * 60, 8, RoundingMode::HalfUp));

        // ---- Earnings ----
        $overtime = $this->overtimeAmount($att['overtime_minutes'], $profile, $dailyRate, $workingHours);
        $adjustments = $this->adjustmentTotals($employee, $run);
        $grossEarnings = Money::sum([$base, $overtime, $adjustments['allowances'], $adjustments['bonuses'], $adjustments['commissions']]);

        // ---- Pay reductions (reduce salary expense) ----
        $absenceOn = (bool) $this->settings->get('payroll', 'absence_deduction_enabled', true);
        $lateOn = (bool) $this->settings->get('payroll', 'late_deduction_enabled', false);
        $absence = $absenceOn ? Money::multiply($att['absent_days'], $dailyRate) : '0.00';
        $unpaidLeave = Money::multiply($att['unpaid_leave_days'], $dailyRate);
        $late = $lateOn ? Money::multiply($att['late_minutes'], $minuteRate) : '0.00';

        // Cap pay reductions so they never exceed gross earnings.
        $payReductions = $this->capAt(Money::sum([$absence, $late, $unpaidLeave]), $grossEarnings);
        $earnedBeforeWithholding = Money::subtract($grossEarnings, $payReductions);

        // ---- Other withholdings (adjustment deductions) ----
        $otherWithheld = $this->capAt($adjustments['other_deductions'], $earnedBeforeWithholding);
        $availableForAdvance = Money::subtract($earnedBeforeWithholding, $otherWithheld);

        // ---- Advance recovery (capped so net >= 0; remainder carries forward) ----
        [$advanceTotal, $advancePlan] = $this->advanceRecovery($employee, $availableForAdvance);

        $totalDeductions = Money::sum([$payReductions, $otherWithheld, $advanceTotal]);
        $net = Money::subtract($grossEarnings, $totalDeductions);
        if (BigDecimal::of($net)->isNegative()) {
            $net = '0.00';
        }

        $snapshot = [
            'base_salary' => $base,
            'daily_rate' => $dailyRate,
            'minute_rate' => $minuteRate,
            'working_days' => $att['working_days'],
            'attended_days' => $att['attended_days'],
            'paid_leave_days' => $att['paid_leave_days'],
            'unpaid_leave_days' => $att['unpaid_leave_days'],
            'absent_days' => $att['absent_days'],
            'overtime' => ['minutes' => $att['overtime_minutes'], 'amount' => $overtime],
            'absence' => ['days' => $att['absent_days'], 'daily_rate' => $dailyRate, 'amount' => $absence, 'enabled' => $absenceOn],
            'late' => ['minutes' => $att['late_minutes'], 'minute_rate' => $minuteRate, 'amount' => $late, 'enabled' => $lateOn],
            'unpaid_leave' => ['days' => $att['unpaid_leave_days'], 'amount' => $unpaidLeave],
            'adjustments' => $adjustments['detail'],
            'other_withheld_accounts' => $adjustments['other_by_account'],
            'advance_plan' => $advancePlan,
        ];

        return [
            'employee_id' => $employee->id,
            'employee_name_snapshot' => $employee->full_name,
            'department_snapshot' => $employee->department?->name,
            'job_title_snapshot' => $employee->job_title,
            'base_salary_ils' => $base,
            'working_days' => $att['working_days'],
            'attended_days' => $att['attended_days'],
            'paid_leave_days' => $att['paid_leave_days'],
            'unpaid_leave_days' => $att['unpaid_leave_days'],
            'absent_days' => $att['absent_days'],
            'late_minutes' => $att['late_minutes'],
            'overtime_minutes' => $att['overtime_minutes'],
            'overtime_amount_ils' => $overtime,
            'allowances_ils' => $adjustments['allowances'],
            'bonuses_ils' => $adjustments['bonuses'],
            'commissions_ils' => $adjustments['commissions'],
            'absence_deduction_ils' => $absence,
            'late_deduction_ils' => $late,
            'unpaid_leave_deduction_ils' => $unpaidLeave,
            'other_deductions_ils' => $otherWithheld,
            'advances_deduction_ils' => $advanceTotal,
            'gross_salary_ils' => $grossEarnings,
            'total_deductions_ils' => $totalDeductions,
            'net_salary_ils' => $net,
            'calculation_snapshot' => $snapshot,
            'advance_plan' => $advancePlan,
            'other_by_account' => $adjustments['other_by_account'],
        ];
    }

    /**
     * Walk the working days of the period and classify each as attended, paid
     * leave, unpaid leave or absent (never counting days before hire).
     *
     * @return array<string,mixed>
     */
    private function attendanceBreakdown(Employee $employee, string $periodStart, string $periodEnd): array
    {
        $workDays = (array) $this->settings->get('attendance', 'work_days', ['sun', 'mon', 'tue', 'wed', 'thu']);
        $dayMap = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];
        $workDayNums = array_map(fn ($d) => $dayMap[$d] ?? -1, $workDays);

        $start = Carbon::parse($periodStart);
        $end = Carbon::parse($periodEnd);
        $hire = $employee->hire_date ? Carbon::parse($employee->hire_date) : null;

        $records = $employee->attendanceRecords()
            ->whereDate('attendance_date', '>=', $periodStart)
            ->whereDate('attendance_date', '<=', $periodEnd)->get()
            ->keyBy(fn ($r) => $r->attendance_date->toDateString());

        $leaves = $employee->leaveRequests()->where('status', 'approved')
            ->where('start_date', '<=', $periodEnd)->where('end_date', '>=', $periodStart)
            ->with('leaveType')->get();

        $working = 0;
        $attended = 0;
        $paidLeave = 0;
        $unpaidLeave = 0;
        $absent = 0;
        $lateMinutes = 0;
        $overtimeMinutes = 0;

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $key = $date->toDateString();
            if ($hire && $date->lt($hire)) {
                continue;
            }
            if (! in_array($date->dayOfWeek, $workDayNums, true)) {
                continue;
            }
            $working++;

            $record = $records->get($key);
            if ($record && in_array($record->status, [
                AttendanceStatus::Present, AttendanceStatus::Late,
                AttendanceStatus::RemoteWork, AttendanceStatus::ExternalMission,
            ], true)) {
                $attended++;
                $lateMinutes += (int) $record->late_minutes;
                $overtimeMinutes += (int) $record->overtime_minutes;

                continue;
            }

            $leave = $leaves->first(fn ($l) => $key >= $l->start_date->toDateString() && $key <= $l->end_date->toDateString());
            if ($leave) {
                if ($leave->leaveType?->is_paid) {
                    $paidLeave++;
                } else {
                    $unpaidLeave++;
                }

                continue;
            }

            $absent++;
        }

        return [
            'working_days' => $working,
            'attended_days' => $attended,
            'paid_leave_days' => $paidLeave,
            'unpaid_leave_days' => $unpaidLeave,
            'absent_days' => $absent,
            'late_minutes' => $lateMinutes,
            'overtime_minutes' => $overtimeMinutes,
        ];
    }

    private function overtimeAmount(int $overtimeMinutes, $profile, string $dailyRate, float $workingHours): string
    {
        if ($overtimeMinutes <= 0) {
            return '0.00';
        }
        $hours = Money::money(Money::of($overtimeMinutes)->dividedBy(60, 8, RoundingMode::HalfUp));

        if ($profile->overtime_rate !== null && Money::isPositive($profile->overtime_rate)) {
            $rate = Money::money($profile->overtime_rate); // absolute ILS/hour
        } else {
            $hourly = Money::of($dailyRate)->dividedBy($workingHours, 8, RoundingMode::HalfUp);
            $multiplier = (float) $this->settings->get('payroll', 'default_overtime_multiplier', 1.5);
            $rate = Money::money($hourly->multipliedBy(Money::of((string) $multiplier)));
        }

        return Money::multiply($hours, $rate);
    }

    /**
     * Sum active adjustments effective for this run (one-time in the month, or
     * recurring within their window). Never double-counts a recurring row.
     *
     * @return array<string,mixed>
     */
    private function adjustmentTotals(Employee $employee, PayrollRun $run): array
    {
        $start = $run->period_start->toDateString();
        $end = $run->period_end->toDateString();

        $rows = $employee->salaryAdjustments()->active()->get()->filter(function ($adj) use ($start, $end, $run) {
            // One-time bound to this run.
            if ($adj->payroll_run_id !== null) {
                return (int) $adj->payroll_run_id === (int) $run->id;
            }
            if ($adj->is_recurring) {
                $from = $adj->effective_date->toDateString();
                $to = $adj->recurring_end_date?->toDateString();

                return $from <= $end && ($to === null || $to >= $start);
            }

            // One-time by date falling inside the period.
            $d = $adj->effective_date->toDateString();

            return $d >= $start && $d <= $end;
        });

        $allowances = $bonuses = $commissions = $otherDeductions = '0.00';
        $detail = [];
        $otherByAccount = [];

        foreach ($rows as $adj) {
            $amount = Money::money($adj->amount_ils);
            $detail[] = ['type' => $adj->adjustment_type->value, 'category' => $adj->category, 'amount' => $amount, 'description' => $adj->description];

            if ($adj->isEarning()) {
                match ($adj->category) {
                    'bonus' => $bonuses = Money::add($bonuses, $amount),
                    'commission' => $commissions = Money::add($commissions, $amount),
                    default => $allowances = Money::add($allowances, $amount),
                };
            } else {
                $otherDeductions = Money::add($otherDeductions, $amount);
                $accId = $adj->gl_account_id ?? 0;
                $otherByAccount[$accId] = Money::add($otherByAccount[$accId] ?? '0.00', $amount);
            }
        }

        return [
            'allowances' => $allowances,
            'bonuses' => $bonuses,
            'commissions' => $commissions,
            'other_deductions' => $otherDeductions,
            'other_by_account' => $otherByAccount,
            'detail' => $detail,
        ];
    }

    /**
     * Plan advance recovery from recoverable advances, capped at the amount
     * available this month. Remainder stays on the advance for next month.
     *
     * @return array{0:string,1:list<array{advance_id:int,amount:string}>}
     */
    private function advanceRecovery(Employee $employee, string $available): array
    {
        $plan = [];
        $total = '0.00';

        if (! Money::isPositive($available)) {
            return ['0.00', []];
        }

        foreach ($employee->advances()->recoverable()->orderBy('approved_date')->get() as $advance) {
            $remainingBudget = Money::subtract($available, $total);
            if (! Money::isPositive($remainingBudget)) {
                break;
            }
            $desired = $advance->installment_ils && Money::isPositive($advance->installment_ils)
                ? Money::money($advance->installment_ils)
                : Money::money($advance->remaining_ils);
            // Never more than the advance's remaining, nor the month's budget.
            $take = Money::isGreaterThan($desired, $advance->remaining_ils) ? Money::money($advance->remaining_ils) : $desired;
            $take = Money::isGreaterThan($take, $remainingBudget) ? $remainingBudget : $take;
            if (! Money::isPositive($take)) {
                continue;
            }
            $plan[] = ['advance_id' => $advance->id, 'amount' => $take];
            $total = Money::add($total, $take);
        }

        return [$total, $plan];
    }

    private function capAt(string $value, string $max): string
    {
        return Money::isGreaterThan($value, $max) ? Money::money($max) : Money::money($value);
    }
}
