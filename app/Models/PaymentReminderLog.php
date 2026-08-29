<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentReminderLog extends Model
{
    public const STATUS_SENT = 'sent';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'invoice_id', 'reminder_rule_id', 'whatsapp_message_id', 'due_date',
        'sent_on', 'status', 'note',
    ];

    protected $casts = [
        'due_date' => 'date',
        'sent_on' => 'date',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(PaymentReminderRule::class, 'reminder_rule_id');
    }

    public function whatsappMessage(): BelongsTo
    {
        return $this->belongsTo(WhatsAppMessage::class, 'whatsapp_message_id');
    }
}
