<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAllocation extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'payment_id', 'invoice_id', 'allocated_usd', 'invoice_exchange_rate',
        'payment_exchange_rate', 'invoice_accounting_value_ils',
        'payment_accounting_value_ils', 'exchange_difference_ils',
        'status', 'reversed_at', 'reversed_by', 'reversal_reason',
    ];

    protected $casts = [
        'allocated_usd' => 'decimal:2',
        'invoice_exchange_rate' => 'decimal:6',
        'payment_exchange_rate' => 'decimal:6',
        'invoice_accounting_value_ils' => 'decimal:2',
        'payment_accounting_value_ils' => 'decimal:2',
        'exchange_difference_ils' => 'decimal:2',
        'reversed_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
