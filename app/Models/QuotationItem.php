<?php

namespace App\Models;

use App\Enums\DiscountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationItem extends Model
{
    protected $fillable = [
        'quotation_id', 'service_id', 'item_name', 'description', 'quantity',
        'unit_price_usd', 'line_subtotal_usd', 'discount_type', 'discount_value',
        'discount_usd', 'tax_rate', 'tax_usd', 'line_total_usd', 'sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price_usd' => 'decimal:4',
        'line_subtotal_usd' => 'decimal:2',
        'discount_value' => 'decimal:4',
        'discount_usd' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_usd' => 'decimal:2',
        'line_total_usd' => 'decimal:2',
        'discount_type' => DiscountType::class,
    ];

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
