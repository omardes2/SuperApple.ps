<?php

namespace App\Models;

use App\Enums\SupplierPaymentStatus;
use App\Exceptions\PostedRecordImmutableException;
use App\Models\Concerns\Auditable;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierPayment extends Model
{
    use Auditable;

    public const LOCKED_FIELDS = [
        'supplier_id', 'payment_date', 'currency', 'amount', 'exchange_rate',
        'amount_ils', 'financial_account_id',
    ];

    protected $fillable = [
        'payment_number', 'supplier_id', 'payment_date', 'currency', 'amount',
        'exchange_rate', 'amount_ils', 'financial_account_id', 'reference_number',
        'notes', 'status', 'posted_at', 'cancelled_at', 'cancelled_by',
        'cancellation_reason', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'amount_ils' => 'decimal:2',
        'status' => SupplierPaymentStatus::class,
        'posted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (SupplierPayment $payment) {
            $original = $payment->getRawOriginal('status');
            if ($original === null || $original === SupplierPaymentStatus::Draft->value) {
                return;
            }
            foreach (self::LOCKED_FIELDS as $field) {
                if ($payment->isDirty($field)) {
                    throw PostedRecordImmutableException::forField($field, 'دفعة المورد');
                }
            }
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    public function activeAllocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class)->where('status', 'active');
    }

    public function isDraft(): bool
    {
        return $this->status === SupplierPaymentStatus::Draft;
    }

    public function isCancelled(): bool
    {
        return $this->status === SupplierPaymentStatus::Cancelled;
    }

    public function allocatedOriginal(): string
    {
        return Money::sum($this->activeAllocations()->pluck('allocated_original'));
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', SupplierPaymentStatus::Posted->value);
    }
}
