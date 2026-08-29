<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Exceptions\IssuedInvoiceImmutableException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id', 'service_id', 'item_name', 'description', 'quantity',
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

    protected static function booted(): void
    {
        // Items of an issued invoice are frozen too.
        $guard = function (InvoiceItem $item) {
            $invoice = $item->invoice()->first();
            if ($invoice && ! $invoice->isDraft()) {
                throw IssuedInvoiceImmutableException::forItems();
            }
        };

        static::saving($guard);
        static::deleting($guard);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
