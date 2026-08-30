<?php

namespace App\Models;

use App\Enums\PaymentCurrency;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\PostedPaymentImmutableException;
use App\Models\Concerns\Auditable;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use Auditable;

    /** Frozen once the payment is posted (enforced in booted()). */
    public const LOCKED_FIELDS = [
        'customer_id', 'payment_date', 'payment_currency', 'payment_amount',
        'exchange_rate', 'usd_equivalent', 'payment_method', 'account_id',
    ];

    protected $fillable = [
        'payment_number', 'customer_id', 'payment_date', 'payment_currency',
        'payment_amount', 'exchange_rate', 'usd_equivalent', 'payment_method',
        'account_id', 'reference_number', 'notes', 'status', 'received_by',
        'posted_at', 'cancelled_at', 'cancelled_by', 'cancellation_reason',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'payment_amount' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'usd_equivalent' => 'decimal:2',
        'payment_currency' => PaymentCurrency::class,
        'payment_method' => PaymentMethod::class,
        'status' => PaymentStatus::class,
        'posted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (Payment $payment) {
            $original = $payment->getRawOriginal('status');
            $wasDraft = $original === null || $original === PaymentStatus::Draft->value;

            if ($wasDraft) {
                return; // draft edits and the draft→posted transition are allowed
            }

            foreach (self::LOCKED_FIELDS as $field) {
                if ($payment->isDirty($field)) {
                    throw PostedPaymentImmutableException::forField($field);
                }
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function whatsappMessages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class)->latest();
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function activeAllocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class)->where('status', 'active');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function isDraft(): bool
    {
        return $this->status === PaymentStatus::Draft;
    }

    public function isPosted(): bool
    {
        return $this->status === PaymentStatus::Posted;
    }

    public function isCancelled(): bool
    {
        return $this->status === PaymentStatus::Cancelled;
    }

    /** Sum of active allocations in USD. */
    public function allocatedUsd(): string
    {
        return Money::sum($this->activeAllocations()->pluck('allocated_usd'));
    }

    /** USD not yet allocated to any invoice (customer credit). */
    public function unallocatedUsd(): string
    {
        return Money::subtract($this->usd_equivalent, $this->allocatedUsd());
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::Posted->value);
    }
}
