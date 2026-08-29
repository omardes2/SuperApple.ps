<?php

namespace App\Models;

use App\Enums\PayrollStatus;
use App\Exceptions\PostedRecordImmutableException;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One payroll run per month. Workflow: draft → calculated → approved → posted
 * (GL) → paid. Once posted it is immutable; corrections are made by reversal.
 */
class PayrollRun extends Model
{
    use Auditable;

    public const LOCKED_FIELDS = ['year', 'month', 'period_start', 'period_end'];

    protected $fillable = [
        'payroll_number', 'year', 'month', 'period_start', 'period_end', 'status',
        'total_gross_ils', 'total_deductions_ils', 'total_advances_ils', 'total_net_ils',
        'calculated_at', 'approved_at', 'approved_by', 'posted_at', 'posted_by',
        'paid_at', 'cancelled_at', 'cancelled_by', 'cancellation_reason', 'notes', 'created_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'total_gross_ils' => 'decimal:2',
        'total_deductions_ils' => 'decimal:2',
        'total_advances_ils' => 'decimal:2',
        'total_net_ils' => 'decimal:2',
        'status' => PayrollStatus::class,
        'calculated_at' => 'datetime',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (PayrollRun $run) {
            $original = $run->getRawOriginal('status');
            if (! in_array($original, [PayrollStatus::Posted->value, PayrollStatus::Paid->value], true)) {
                return;
            }
            $allowed = ['status', 'paid_at', 'cancelled_at', 'cancelled_by', 'cancellation_reason', 'updated_at'];
            foreach (self::LOCKED_FIELDS as $field) {
                if ($run->isDirty($field) && ! in_array($field, $allowed, true)) {
                    throw PostedRecordImmutableException::forField($field, 'مسير الرواتب');
                }
            }
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PayrollPayment::class);
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function isPosted(): bool
    {
        return $this->status->isPosted();
    }

    public function periodLabel(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->whereIn('status', [PayrollStatus::Posted->value, PayrollStatus::Paid->value]);
    }
}
