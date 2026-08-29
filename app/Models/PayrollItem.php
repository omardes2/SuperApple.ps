<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One employee's payslip inside a payroll run — a frozen snapshot (name,
 * department, salary, attendance) captured at calculation. Remaining payable is
 * derived from posted payments.
 */
class PayrollItem extends Model
{
    protected $fillable = [
        'payroll_run_id', 'employee_id', 'employee_name_snapshot', 'department_snapshot',
        'job_title_snapshot', 'base_salary_ils', 'working_days', 'attended_days',
        'paid_leave_days', 'unpaid_leave_days', 'absent_days', 'late_minutes', 'overtime_minutes',
        'overtime_amount_ils', 'allowances_ils', 'bonuses_ils', 'commissions_ils',
        'absence_deduction_ils', 'late_deduction_ils', 'unpaid_leave_deduction_ils',
        'other_deductions_ils', 'advances_deduction_ils', 'gross_salary_ils',
        'total_deductions_ils', 'net_salary_ils', 'paid_amount_ils', 'remaining_payable_ils',
        'notes', 'calculation_snapshot',
    ];

    protected $casts = [
        'base_salary_ils' => 'decimal:2',
        'working_days' => 'decimal:2',
        'attended_days' => 'decimal:2',
        'paid_leave_days' => 'decimal:2',
        'unpaid_leave_days' => 'decimal:2',
        'absent_days' => 'decimal:2',
        'overtime_amount_ils' => 'decimal:2',
        'allowances_ils' => 'decimal:2',
        'bonuses_ils' => 'decimal:2',
        'commissions_ils' => 'decimal:2',
        'absence_deduction_ils' => 'decimal:2',
        'late_deduction_ils' => 'decimal:2',
        'unpaid_leave_deduction_ils' => 'decimal:2',
        'other_deductions_ils' => 'decimal:2',
        'advances_deduction_ils' => 'decimal:2',
        'gross_salary_ils' => 'decimal:2',
        'total_deductions_ils' => 'decimal:2',
        'net_salary_ils' => 'decimal:2',
        'paid_amount_ils' => 'decimal:2',
        'remaining_payable_ils' => 'decimal:2',
        'calculation_snapshot' => 'array',
    ];

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PayrollPayment::class);
    }

    /** Sum of posted (non-reversed) salary payments. */
    public function paidAmount(): string
    {
        return Money::sum($this->payments()->where('status', 'posted')->pluck('amount_ils'));
    }
}
