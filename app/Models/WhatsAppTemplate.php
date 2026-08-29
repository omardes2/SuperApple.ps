<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WhatsAppTemplate extends Model
{
    use Auditable;

    protected $table = 'whatsapp_templates';

    // Canonical template keys used by automated flows.
    public const KEY_INVOICE_ISSUED = 'invoice_issued';

    public const KEY_REMINDER_BEFORE_DUE = 'payment_reminder_before_due';

    public const KEY_REMINDER_DUE_TODAY = 'payment_reminder_due_today';

    public const KEY_REMINDER_OVERDUE = 'payment_reminder_overdue';

    public const KEY_REMINDER_MANUAL = 'payment_reminder_manual';

    public const KEY_PAYMENT_RECEIVED = 'payment_received';

    public const KEY_SUBSCRIPTION_INVOICE = 'subscription_invoice_created';

    public const KEY_MANUAL = 'manual_message';

    protected $fillable = [
        'name', 'key', 'category', 'language', 'body', 'is_active',
        'variables_schema', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'variables_schema' => 'array',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
