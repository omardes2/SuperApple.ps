<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAdvanceRecovery extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'employee_advance_id', 'payroll_run_id', 'payroll_item_id', 'amount_ils', 'status',
    ];

    protected $casts = ['amount_ils' => 'decimal:2'];

    public function advance(): BelongsTo
    {
        return $this->belongsTo(EmployeeAdvance::class, 'employee_advance_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
