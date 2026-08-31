<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer's pre-system balance as a real accounting document (never an
 * invoice). USD is the official amount; ILS is snapshotted at the historical
 * rate entered manually. A `debit` balance is a receivable payments can reduce;
 * a `credit` balance is money in the customer's favour. Immutable once posted —
 * corrections go through reverse(), never an edit.
 */
class CustomerOpeningBalance extends Model
{
    use Auditable;

    public const TYPE_DEBIT = 'debit';

    public const TYPE_CREDIT = 'credit';

    public const STATUS_POSTED = 'posted';

    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'customer_id', 'balance_date', 'type', 'amount_usd', 'exchange_rate',
        'amount_ils', 'paid_usd_equivalent', 'remaining_usd', 'status',
        'journal_entry_id', 'notes', 'posted_at', 'posted_by', 'reversed_at',
        'reversed_by', 'reversal_reason', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'balance_date' => 'date',
        'amount_usd' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'amount_ils' => 'decimal:2',
        'paid_usd_equivalent' => 'decimal:2',
        'remaining_usd' => 'decimal:2',
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'opening_balance_id');
    }

    public function isDebit(): bool
    {
        return $this->type === self::TYPE_DEBIT;
    }

    public function isCredit(): bool
    {
        return $this->type === self::TYPE_CREDIT;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isReversed(): bool
    {
        return $this->status === self::STATUS_REVERSED;
    }

    /** Only a debit balance with remaining > 0 can receive a payment allocation. */
    public function acceptsAllocation(): bool
    {
        return $this->isPosted() && $this->isDebit() && (float) $this->remaining_usd > 0;
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    public function scopeDebit(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_DEBIT);
    }

    public function scopeCredit(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_CREDIT);
    }
}
