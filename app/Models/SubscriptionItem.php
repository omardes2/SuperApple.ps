<?php

namespace App\Models;

use App\Enums\DiscountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionItem extends Model
{
    protected $fillable = [
        'subscription_id', 'service_id', 'item_name', 'description', 'quantity',
        'unit_price_usd', 'discount_type', 'discount_value', 'tax_rate', 'sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price_usd' => 'decimal:4',
        'discount_value' => 'decimal:4',
        'tax_rate' => 'decimal:2',
        'discount_type' => DiscountType::class,
        'sort_order' => 'integer',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** Shape this snapshot as an InvoiceService line input. */
    public function toLineArray(): array
    {
        return [
            'service_id' => $this->service_id,
            'item_name' => $this->item_name,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_price_usd' => $this->unit_price_usd,
            'discount_type' => $this->discount_type?->value,
            'discount_value' => $this->discount_value,
            'tax_rate' => $this->tax_rate,
        ];
    }
}
