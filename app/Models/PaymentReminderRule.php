<?php

namespace App\Models;

use App\Enums\ReminderTimingType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class PaymentReminderRule extends Model
{
    use Auditable;

    protected $fillable = [
        'name', 'offset_days', 'timing_type', 'template_id', 'is_active',
        'send_time', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'offset_days' => 'integer',
        'timing_type' => ReminderTimingType::class,
        'is_active' => 'boolean',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(WhatsAppTemplate::class, 'template_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** The date this rule targets for an invoice with the given due date. */
    public function sendDateFor(Carbon $dueDate): Carbon
    {
        return $this->timing_type->sendDateFor($dueDate, (int) $this->offset_days);
    }
}
