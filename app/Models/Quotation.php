<?php

namespace App\Models;

use App\Enums\QuotationStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quotation extends Model
{
    use Auditable;

    protected $fillable = [
        'quotation_number', 'customer_id', 'project_id', 'quotation_date', 'valid_until',
        'currency', 'subtotal_usd', 'discount_usd', 'tax_usd', 'total_usd', 'status',
        'notes', 'terms', 'customer_snapshot', 'sent_at', 'accepted_at', 'rejected_at',
        'cancelled_at', 'accepted_by', 'revision_of', 'converted_invoice_id',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'quotation_date' => 'date',
        'valid_until' => 'date',
        'subtotal_usd' => 'decimal:2',
        'discount_usd' => 'decimal:2',
        'tax_usd' => 'decimal:2',
        'total_usd' => 'decimal:2',
        'status' => QuotationStatus::class,
        'customer_snapshot' => 'array',
        'sent_at' => 'datetime',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'converted_invoice_id');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function isExpired(): bool
    {
        return $this->valid_until
            && $this->valid_until->isPast()
            && in_array($this->status, [QuotationStatus::Draft, QuotationStatus::Sent], true);
    }

    /**
     * Effective status, computing Expired on the fly (never stored/scheduled).
     */
    public function effectiveStatus(): QuotationStatus
    {
        return $this->isExpired() ? QuotationStatus::Expired : $this->status;
    }
}
