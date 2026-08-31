<?php

namespace App\Models;

use App\Enums\WhatsAppMessageStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppMessage extends Model
{
    protected $table = 'whatsapp_messages';

    public const DIRECTION_OUTBOUND = 'outbound';

    public const DIRECTION_INBOUND = 'inbound';

    protected $fillable = [
        'customer_id', 'invoice_id', 'payment_id', 'subscription_id', 'template_id',
        'phone', 'message_body', 'document_name', 'provider', 'provider_message_id', 'direction',
        'status', 'scheduled_for', 'sent_at', 'delivered_at', 'read_at',
        'failed_at', 'failure_reason', 'created_by',
    ];

    protected $casts = [
        'status' => WhatsAppMessageStatus::class,
        'scheduled_for' => 'datetime',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(WhatsAppTemplate::class, 'template_id');
    }

    public function isRetryable(): bool
    {
        return $this->status->isRetryable();
    }

    public function scopeOutbound(Builder $query): Builder
    {
        return $query->where('direction', self::DIRECTION_OUTBOUND);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', WhatsAppMessageStatus::Failed->value);
    }
}
