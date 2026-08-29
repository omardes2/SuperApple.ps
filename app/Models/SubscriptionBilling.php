<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The billing ledger for a subscription: one row per period ever attempted.
 * The unique (subscription_id, period_start, period_end) index guarantees a
 * period is never billed twice.
 */
class SubscriptionBilling extends Model
{
    public const STATUS_GENERATED = 'generated'; // draft invoice created

    public const STATUS_ISSUED = 'issued';       // invoice created and issued

    public const STATUS_SKIPPED = 'skipped';     // intentionally not billed

    public const STATUS_FAILED = 'failed';       // an error prevented billing

    protected $fillable = [
        'subscription_id', 'invoice_id', 'period_start', 'period_end',
        'billing_date', 'status', 'error_message',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'billing_date' => 'date',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
