<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Exceptions\IssuedInvoiceImmutableException;
use App\Models\Concerns\Auditable;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use Auditable;

    /**
     * Financial fields that are frozen the moment the invoice leaves Draft.
     * A guard in booted() enforces this even against direct model writes.
     */
    public const LOCKED_FIELDS = [
        'customer_id', 'project_id', 'invoice_date', 'due_date', 'currency',
        'subtotal_usd', 'discount_usd', 'tax_usd', 'total_usd',
        'exchange_rate', 'total_ils_at_issue', 'customer_snapshot', 'quotation_id',
    ];

    protected $fillable = [
        'invoice_number', 'quotation_id', 'subscription_id', 'customer_id', 'project_id', 'invoice_date',
        'due_date', 'currency', 'subtotal_usd', 'discount_usd', 'tax_usd', 'total_usd',
        'exchange_rate', 'total_ils_at_issue', 'paid_usd_equivalent', 'remaining_usd',
        'status', 'issued_at', 'sent_at', 'cancelled_at', 'cancellation_reason',
        'cancelled_by', 'customer_snapshot', 'notes', 'terms', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'subtotal_usd' => 'decimal:2',
        'discount_usd' => 'decimal:2',
        'tax_usd' => 'decimal:2',
        'total_usd' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'total_ils_at_issue' => 'decimal:2',
        'paid_usd_equivalent' => 'decimal:2',
        'remaining_usd' => 'decimal:2',
        'status' => InvoiceStatus::class,
        'customer_snapshot' => 'array',
        'issued_at' => 'datetime',
        'sent_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Domain-level immutability: reject any change to a locked field once the
        // invoice has been issued. The transition draft → issued is allowed
        // because at that point the ORIGINAL status is still Draft.
        static::updating(function (Invoice $invoice) {
            // Use the RAW original so enum casting doesn't obscure the string.
            $originalStatus = $invoice->getRawOriginal('status');
            $wasDraft = $originalStatus === null || $originalStatus === InvoiceStatus::Draft->value;

            if ($wasDraft) {
                return;
            }

            foreach (self::LOCKED_FIELDS as $field) {
                if ($invoice->isDirty($field)) {
                    throw IssuedInvoiceImmutableException::forField($field);
                }
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function whatsappMessages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class)->latest();
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function activeAllocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class)->where('status', 'active');
    }

    /**
     * May this invoice receive a payment allocation? Issued/Sent/PartiallyPaid
     * (and computed-overdue) are eligible; Draft/Cancelled/Paid are not.
     */
    public function acceptsAllocation(): bool
    {
        return in_array($this->status, [
            InvoiceStatus::Issued, InvoiceStatus::Sent,
            InvoiceStatus::PartiallyPaid, InvoiceStatus::Overdue,
        ], true) && Money::isPositive($this->remaining_usd);
    }

    public function isDraft(): bool
    {
        return $this->status === InvoiceStatus::Draft;
    }

    public function isIssued(): bool
    {
        return $this->status->isIssuedAndActive();
    }

    public function isCancelled(): bool
    {
        return $this->status === InvoiceStatus::Cancelled;
    }

    /**
     * Whether the invoice is overdue right now — computed, never stored.
     */
    public function isOverdue(): bool
    {
        return $this->due_date
            && $this->due_date->isPast()
            && Money::isPositive($this->remaining_usd)
            && ! in_array($this->status, [InvoiceStatus::Paid, InvoiceStatus::Cancelled, InvoiceStatus::Draft], true);
    }

    /**
     * The status to display, folding in the computed Overdue state so no
     * scheduler is needed to keep the flag correct.
     */
    public function effectiveStatus(): InvoiceStatus
    {
        return $this->isOverdue() ? InvoiceStatus::Overdue : $this->status;
    }

    public function ilsEquivalent(): ?string
    {
        return $this->total_ils_at_issue;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '!=', InvoiceStatus::Cancelled->value);
    }

    public function scopeIssued(Builder $query): Builder
    {
        return $query->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Cancelled->value]);
    }
}
