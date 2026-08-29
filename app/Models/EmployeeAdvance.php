<?php

namespace App\Models;

use App\Enums\AdvanceStatus;
use App\Enums\AdvanceType;
use App\Exceptions\PostedRecordImmutableException;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An employee advance or (interest-free) loan: money paid ahead of salary and
 * recovered from later payrolls. It is an asset (Employee Advances Receivable),
 * never a salary expense, until recovered.
 */
class EmployeeAdvance extends Model
{
    use Auditable;

    /** Financial fields frozen once paid. */
    public const LOCKED_FIELDS = ['employee_id', 'type', 'amount_ils', 'financial_account_id'];

    protected $fillable = [
        'advance_number', 'employee_id', 'type', 'request_date', 'approved_date',
        'amount_ils', 'remaining_ils', 'installment_ils', 'installments', 'status',
        'financial_account_id', 'paid_at', 'approved_by', 'notes',
        'cancelled_at', 'cancelled_by', 'cancellation_reason', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'request_date' => 'date',
        'approved_date' => 'date',
        'amount_ils' => 'decimal:2',
        'remaining_ils' => 'decimal:2',
        'installment_ils' => 'decimal:2',
        'type' => AdvanceType::class,
        'status' => AdvanceStatus::class,
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (EmployeeAdvance $advance) {
            $original = $advance->getRawOriginal('status');
            // Locked once money has gone out (paid or beyond).
            if (in_array($original, [AdvanceStatus::Draft->value, AdvanceStatus::Approved->value, null], true)) {
                return;
            }
            foreach (self::LOCKED_FIELDS as $field) {
                if ($advance->isDirty($field)) {
                    throw PostedRecordImmutableException::forField($field, 'السلفة');
                }
            }
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function recoveries(): HasMany
    {
        return $this->hasMany(EmployeeAdvanceRecovery::class);
    }

    public function isDraft(): bool
    {
        return $this->status === AdvanceStatus::Draft;
    }

    public function isCancelled(): bool
    {
        return $this->status === AdvanceStatus::Cancelled;
    }

    public function isRecoverable(): bool
    {
        return $this->status->isRecoverable();
    }

    public function scopeRecoverable(Builder $query): Builder
    {
        return $query->whereIn('status', [AdvanceStatus::Paid->value, AdvanceStatus::PartiallyRecovered->value])
            ->where('remaining_ils', '>', 0);
    }
}
