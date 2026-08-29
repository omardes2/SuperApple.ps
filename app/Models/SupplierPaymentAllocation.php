<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPaymentAllocation extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'supplier_payment_id', 'supplier_bill_id', 'allocated_original',
        'bill_accounting_value_ils', 'payment_accounting_value_ils',
        'exchange_difference_ils', 'status', 'reversed_at', 'reversed_by', 'reversal_reason',
    ];

    protected $casts = [
        'allocated_original' => 'decimal:2',
        'bill_accounting_value_ils' => 'decimal:2',
        'payment_accounting_value_ils' => 'decimal:2',
        'exchange_difference_ils' => 'decimal:2',
        'reversed_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class, 'supplier_payment_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class, 'supplier_bill_id');
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
