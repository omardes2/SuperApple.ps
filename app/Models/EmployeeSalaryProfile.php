<?php

namespace App\Models;

use App\Enums\SalaryType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Effective-dated salary. The salary used for a payroll month is the profile
 * whose [effective_from, effective_to] window covers that month; a later raise
 * never changes past payrolls.
 */
class EmployeeSalaryProfile extends Model
{
    use Auditable;

    protected $fillable = [
        'employee_id', 'effective_from', 'effective_to', 'base_salary_ils',
        'salary_type', 'working_days_basis', 'daily_rate', 'hourly_rate',
        'overtime_rate', 'notes', 'status', 'approved_by', 'created_by',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'base_salary_ils' => 'decimal:2',
        'daily_rate' => 'decimal:2',
        'hourly_rate' => 'decimal:2',
        'overtime_rate' => 'decimal:2',
        'salary_type' => SalaryType::class,
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Profiles in effect on a given date, latest first. */
    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->orderByDesc('effective_from');
    }
}
