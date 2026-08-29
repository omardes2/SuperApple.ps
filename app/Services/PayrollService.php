<?php

namespace App\Services;

use App\Enums\PayrollStatus;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\User;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Owns the payroll lifecycle: create run → calculate (snapshot every employee)
 * → approve (freeze) → post (GL accrual + commit advance recoveries) → pay.
 * Posted runs are immutable; corrections are made by reversal. All ILS.
 */
class PayrollService
{
    public function __construct(
        private readonly PayrollCalculator $calculator,
        private readonly EmployeeAdvanceService $advances,
        private readonly LedgerPostingService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    public function createRun(int $year, int $month, ?string $notes = null): PayrollRun
    {
        if (PayrollRun::where('year', $year)->where('month', $month)->exists()) {
            throw new RuntimeException('يوجد مسير رواتب لهذا الشهر بالفعل.');
        }

        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $run = PayrollRun::create([
            'payroll_number' => sprintf('PAYROLL-%04d-%02d', $year, $month),
            'year' => $year,
            'month' => $month,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'status' => PayrollStatus::Draft,
            'notes' => $notes,
            'created_by' => Auth::id(),
        ]);

        $this->audit->log('payroll_created', $run, 'Payroll', description: 'إنشاء مسير رواتب '.$run->periodLabel());

        return $run;
    }

    /** (Re)build all payroll items from the current source data. Draft/Calculated only. */
    public function calculate(PayrollRun $run): PayrollRun
    {
        if (! $run->isEditable()) {
            throw new RuntimeException('لا يمكن إعادة الاحتساب بعد الاعتماد.');
        }

        return DB::transaction(function () use ($run) {
            $run->items()->delete();

            $employees = Employee::active()->with('department')->get();
            $gross = $deductions = $advancesTotal = $net = '0.00';

            foreach ($employees as $employee) {
                $calc = $this->calculator->calculate($employee, $run);
                if ($calc === null) {
                    continue; // no salary profile for this month
                }

                unset($calc['advance_plan'], $calc['other_by_account']);
                $calc['payroll_run_id'] = $run->id;
                $calc['paid_amount_ils'] = '0.00';
                $calc['remaining_payable_ils'] = $calc['net_salary_ils'];
                PayrollItem::create($calc);

                $gross = Money::add($gross, $calc['gross_salary_ils']);
                $deductions = Money::add($deductions, $calc['total_deductions_ils']);
                $advancesTotal = Money::add($advancesTotal, $calc['advances_deduction_ils']);
                $net = Money::add($net, $calc['net_salary_ils']);
            }

            $run->update([
                'status' => PayrollStatus::Calculated,
                'total_gross_ils' => $gross,
                'total_deductions_ils' => $deductions,
                'total_advances_ils' => $advancesTotal,
                'total_net_ils' => $net,
                'calculated_at' => now(),
            ]);

            $this->audit->log('payroll_calculated', $run, 'Payroll',
                new: ['gross' => $gross, 'net' => $net], description: 'احتساب الرواتب');

            return $run;
        });
    }

    public function approve(PayrollRun $run, User $actor): PayrollRun
    {
        if ($run->status !== PayrollStatus::Calculated) {
            throw new RuntimeException('يجب احتساب الرواتب قبل اعتمادها.');
        }
        $run->update(['status' => PayrollStatus::Approved, 'approved_at' => now(), 'approved_by' => $actor->id]);
        $this->audit->log('payroll_approved', $run, 'Payroll', description: 'اعتماد الرواتب');

        return $run;
    }

    /** Post the payroll accrual to the GL and commit advance recoveries. */
    public function post(PayrollRun $run): PayrollRun
    {
        if ($run->status !== PayrollStatus::Approved) {
            throw new RuntimeException('يجب اعتماد الرواتب قبل ترحيلها.');
        }

        return DB::transaction(function () use ($run) {
            $run = PayrollRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            $run->loadMissing('items');

            if ($run->items->isEmpty()) {
                throw new RuntimeException('لا يمكن ترحيل مسير رواتب بدون بنود.');
            }

            // Commit advance recoveries from each item's plan (re-capped to live remaining).
            foreach ($run->items as $item) {
                foreach ((array) ($item->calculation_snapshot['advance_plan'] ?? []) as $planned) {
                    $advance = EmployeeAdvance::find($planned['advance_id']);
                    if ($advance === null || ! $advance->isRecoverable()) {
                        continue;
                    }
                    $amount = Money::isGreaterThan($planned['amount'], $advance->remaining_ils)
                        ? Money::money($advance->remaining_ils)
                        : Money::money($planned['amount']);
                    if (Money::isPositive($amount)) {
                        $this->advances->recordRecovery($advance, $item, $amount);
                    }
                }
            }

            // GL accrual (atomic — failure rolls back recoveries too).
            $this->ledger->postPayrollRun($run);

            $run->update(['status' => PayrollStatus::Posted, 'posted_at' => now(), 'posted_by' => Auth::id()]);
            $this->audit->log('payroll_posted', $run, 'Payroll',
                new: ['gross' => $run->total_gross_ils, 'net' => $run->total_net_ils], description: 'ترحيل الرواتب محاسبياً');

            return $run;
        });
    }

    public function cancel(PayrollRun $run, User $actor, string $reason): PayrollRun
    {
        if ($run->isPosted()) {
            throw new RuntimeException('لا يمكن إلغاء مسير مُرحّل مباشرة — استخدم العكس (Reverse).');
        }
        if ($run->status === PayrollStatus::Cancelled) {
            throw new RuntimeException('المسير ملغى بالفعل.');
        }
        if (blank($reason)) {
            throw new RuntimeException('يجب إدخال سبب الإلغاء.');
        }

        $run->update([
            'status' => PayrollStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => $actor->id,
            'cancellation_reason' => $reason,
        ]);
        $this->audit->log('payroll_cancelled', $run, 'Payroll', new: ['reason' => $reason], description: 'إلغاء المسير');

        return $run;
    }

    /** Reverse a posted payroll: reverse GL + advance recoveries. Blocked while salary payments exist. */
    public function reverse(PayrollRun $run, User $actor, string $reason): PayrollRun
    {
        if (! $run->isPosted()) {
            throw new RuntimeException('يمكن عكس المسيرات المُرحّلة فقط.');
        }
        if (blank($reason)) {
            throw new RuntimeException('يجب إدخال سبب العكس.');
        }
        if ($run->payments()->where('status', 'posted')->exists()) {
            throw new RuntimeException('لا يمكن عكس المسير قبل عكس مدفوعات الرواتب المرتبطة به.');
        }

        return DB::transaction(function () use ($run, $actor, $reason) {
            $this->ledger->reversePayrollRun($run, $reason);
            $this->advances->reverseRecoveriesForRun($run->id);

            $run->update([
                'status' => PayrollStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancellation_reason' => $reason,
            ]);
            $this->audit->log('payroll_reversed', $run, 'Payroll', new: ['reason' => $reason], description: 'عكس مسير الرواتب');

            return $run;
        });
    }

    /** Refresh an item's paid/remaining from its posted payments and roll up run status. */
    public function refreshItemPayment(PayrollItem $item): void
    {
        $paid = $item->paidAmount();
        $remaining = Money::subtract($item->net_salary_ils, $paid);
        if (BigDecimal::of($remaining)->isNegative()) {
            $remaining = '0.00';
        }
        $item->update(['paid_amount_ils' => $paid, 'remaining_payable_ils' => $remaining]);

        $run = $item->payrollRun;
        if ($run->status === PayrollStatus::Posted && $run->items()->where('remaining_payable_ils', '>', 0)->doesntExist()) {
            $run->update(['status' => PayrollStatus::Paid, 'paid_at' => now()]);
        } elseif ($run->status === PayrollStatus::Paid && $run->items()->where('remaining_payable_ils', '>', 0)->exists()) {
            $run->update(['status' => PayrollStatus::Posted, 'paid_at' => null]);
        }
    }
}
