<?php

namespace App\Models;

use App\Enums\SupplierBillStatus;
use App\Exceptions\PostedRecordImmutableException;
use App\Models\Concerns\Auditable;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A vendor bill (accounts payable). Total is stored in the bill's original
 * currency plus an ILS accounting value at the bill's exchange rate. Payments
 * reduce the remaining (original) and, when the bill is USD, realise FX
 * gain/loss against the payment-date rate.
 */
class SupplierBill extends Model
{
    use Auditable;

    /** Financial fields frozen once posted. */
    public const LOCKED_FIELDS = [
        'supplier_id', 'bill_date', 'currency', 'subtotal', 'tax', 'total',
        'exchange_rate', 'total_ils',
    ];

    protected $fillable = [
        'bill_number', 'supplier_id', 'project_id', 'bill_date', 'due_date',
        'currency', 'subtotal', 'tax', 'total', 'exchange_rate', 'total_ils',
        'paid_original', 'remaining_original', 'status', 'reference_number', 'notes',
        'posted_at', 'cancelled_at', 'cancelled_by', 'cancellation_reason',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'bill_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'total_ils' => 'decimal:2',
        'paid_original' => 'decimal:2',
        'remaining_original' => 'decimal:2',
        'status' => SupplierBillStatus::class,
        'posted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (SupplierBill $bill) {
            $original = $bill->getRawOriginal('status');
            if ($original === null || $original === SupplierBillStatus::Draft->value) {
                return; // draft edits and draft→posted allowed
            }
            // paid/remaining/status transitions are allowed post-posting.
            foreach (self::LOCKED_FIELDS as $field) {
                if ($bill->isDirty($field)) {
                    throw PostedRecordImmutableException::forField($field, 'الفاتورة الواردة');
                }
            }
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplierBillItem::class)->orderBy('sort_order')->orderBy('id');
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
        return $this->status === SupplierBillStatus::Draft;
    }

    public function isCancelled(): bool
    {
        return $this->status === SupplierBillStatus::Cancelled;
    }

    /** May this bill receive a supplier-payment allocation? */
    public function acceptsAllocation(): bool
    {
        return $this->status->isOpenPayable()
            && Money::isPositive($this->remaining_original);
    }

    public function scopeOpenPayable(Builder $query): Builder
    {
        return $query->whereIn('status', [
            SupplierBillStatus::Posted->value,
            SupplierBillStatus::PartiallyPaid->value,
        ]);
    }
}
