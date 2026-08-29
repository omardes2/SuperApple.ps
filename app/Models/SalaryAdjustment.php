<?php

namespace App\Models;

use App\Enums\AdjustmentType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-time or recurring earning/deduction added to payroll. Recurring
 * adjustments apply to every payroll month within their effective window
 * (each month once — never duplicated).
 */
class SalaryAdjustment extends Model
{
    use Auditable;

    protected $fillable = [
        'employee_id', 'payroll_run_id', 'adjustment_type', 'category', 'amount_ils',
        'effective_date', 'recurring_end_date', 'description', 'is_recurring',
        'gl_account_id', 'status', 'approved_by', 'created_by',
    ];

    protected $casts = [
        'amount_ils' => 'decimal:2',
        'effective_date' => 'date',
        'recurring_end_date' => 'date',
        'is_recurring' => 'boolean',
        'adjustment_type' => AdjustmentType::class,
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'gl_account_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function isEarning(): bool
    {
        return $this->adjustment_type === AdjustmentType::Earning;
    }
}
