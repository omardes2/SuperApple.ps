<?php

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\SubscriptionStatus;
use App\Models\Concerns\Auditable;
use App\Support\Money;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recurring contract with a customer. It never posts accounting on its own —
 * it merely describes *what* and *how often* to bill. Every actual invoice is
 * produced through InvoiceService and follows all standard invoice rules.
 */
class Subscription extends Model
{
    use Auditable;

    protected $fillable = [
        'subscription_number', 'customer_id', 'project_id', 'name', 'description',
        'billing_cycle', 'billing_interval', 'start_date', 'end_date', 'next_billing_date',
        'last_billed_at', 'payment_terms_days', 'currency', 'subtotal_usd', 'discount_usd',
        'tax_usd', 'total_usd', 'auto_generate_invoice', 'auto_issue_invoice', 'status',
        'notes', 'terms', 'activated_at', 'paused_at', 'cancelled_at', 'cancelled_by',
        'cancellation_reason', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'next_billing_date' => 'date',
        'last_billed_at' => 'datetime',
        'billing_interval' => 'integer',
        'payment_terms_days' => 'integer',
        'subtotal_usd' => 'decimal:2',
        'discount_usd' => 'decimal:2',
        'tax_usd' => 'decimal:2',
        'total_usd' => 'decimal:2',
        'auto_generate_invoice' => 'boolean',
        'auto_issue_invoice' => 'boolean',
        'billing_cycle' => BillingCycle::class,
        'status' => SubscriptionStatus::class,
        'activated_at' => 'datetime',
        'paused_at' => 'datetime',
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
        return $this->hasMany(SubscriptionItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function billings(): HasMany
    {
        return $this->hasMany(SubscriptionBilling::class)->latest('period_start');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function isDraft(): bool
    {
        return $this->status === SubscriptionStatus::Draft;
    }

    public function isActive(): bool
    {
        return $this->status === SubscriptionStatus::Active;
    }

    public function isPaused(): bool
    {
        return $this->status === SubscriptionStatus::Paused;
    }

    public function isCancelled(): bool
    {
        return $this->status === SubscriptionStatus::Cancelled;
    }

    /** Monthly-normalised contracted value (MRR contribution) in USD. */
    public function monthlyRecurringRevenue(): string
    {
        $months = $this->billing_cycle->monthsPerPeriod((int) $this->billing_interval);
        if ($months <= 0) {
            return '0.00';
        }

        return Money::money(Money::of($this->total_usd)->dividedBy(
            Money::of(sprintf('%.6F', $months)),
            Money::MONEY_SCALE + 6,
            RoundingMode::HalfUp,
        ));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::Active->value);
    }

    /** Active subscriptions whose next billing date is due on or before a date. */
    public function scopeDueForBilling(Builder $query, string $onDate): Builder
    {
        return $query->where('status', SubscriptionStatus::Active->value)
            ->whereNotNull('next_billing_date')
            ->whereDate('next_billing_date', '<=', $onDate);
    }
}
