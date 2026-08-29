<?php

namespace App\Models;

use App\Enums\ExpenseStatus;
use App\Exceptions\PostedRecordImmutableException;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    use Auditable;

    /** Frozen once posted. */
    public const LOCKED_FIELDS = [
        'expense_date', 'category_id', 'currency', 'amount', 'exchange_rate',
        'amount_ils', 'tax_amount', 'financial_account_id', 'supplier_id',
    ];

    protected $fillable = [
        'expense_number', 'expense_date', 'category_id', 'supplier_id', 'project_id',
        'employee_id', 'description', 'currency', 'amount', 'exchange_rate', 'amount_ils',
        'payment_method', 'financial_account_id', 'reference_number', 'tax_amount', 'notes',
        'status', 'approved_by', 'posted_at', 'cancelled_at', 'cancelled_by',
        'cancellation_reason', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'amount_ils' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'status' => ExpenseStatus::class,
        'posted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (Expense $expense) {
            $original = $expense->getRawOriginal('status');
            // Locked once posted; draft/approved edits allowed.
            if ($original !== ExpenseStatus::Posted->value) {
                return;
            }
            $allowed = ['status', 'cancelled_at', 'cancelled_by', 'cancellation_reason', 'updated_at', 'updated_by'];
            foreach (self::LOCKED_FIELDS as $field) {
                if ($expense->isDirty($field) && ! in_array($field, $allowed, true)) {
                    throw PostedRecordImmutableException::forField($field, 'المصروف');
                }
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function isDraft(): bool
    {
        return $this->status === ExpenseStatus::Draft;
    }

    public function isPosted(): bool
    {
        return $this->status === ExpenseStatus::Posted;
    }

    public function isCancelled(): bool
    {
        return $this->status === ExpenseStatus::Cancelled;
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', ExpenseStatus::Posted->value);
    }
}
