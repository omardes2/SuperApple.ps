<?php

namespace App\Models;

use App\Enums\JournalStatus;
use App\Exceptions\PostedRecordImmutableException;
use App\Models\Concerns\Auditable;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A double-entry journal entry. Once posted it is immutable — corrections are
 * made by posting a reversal entry, never by editing. The header carries the
 * source document + posting type, which together are unique (idempotency).
 */
class JournalEntry extends Model
{
    use Auditable;

    /** Frozen once the entry leaves Draft. */
    public const LOCKED_FIELDS = [
        'entry_date', 'source_type', 'source_id', 'posting_type', 'status',
    ];

    protected $fillable = [
        'journal_number', 'entry_date', 'source_type', 'source_id', 'posting_type',
        'description', 'status', 'is_reversal', 'posted_at', 'reversed_at',
        'reversal_entry_id', 'created_by', 'posted_by',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'status' => JournalStatus::class,
        'is_reversal' => 'boolean',
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (JournalEntry $entry) {
            $original = $entry->getRawOriginal('status');
            $wasDraft = $original === null || $original === JournalStatus::Draft->value;

            // Allow the draft→posted transition and marking a posted entry as
            // reversed (status + reversed_at + reversal_entry_id only).
            if ($wasDraft) {
                return;
            }

            $allowedWhenReversing = ['status', 'reversed_at', 'reversal_entry_id', 'updated_at'];
            foreach (self::LOCKED_FIELDS as $field) {
                if ($entry->isDirty($field) && ! in_array($field, $allowedWhenReversing, true)) {
                    throw PostedRecordImmutableException::forField($field, 'القيد');
                }
            }
        });

        static::deleting(function (JournalEntry $entry) {
            if ($entry->getRawOriginal('status') !== JournalStatus::Draft->value) {
                throw PostedRecordImmutableException::forField('delete', 'القيد المُرحّل');
            }
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function reversalEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_entry_id');
    }

    public function isPosted(): bool
    {
        return $this->status === JournalStatus::Posted;
    }

    public function isReversed(): bool
    {
        return $this->status === JournalStatus::Reversed;
    }

    public function totalDebit(): string
    {
        return Money::sum($this->lines->pluck('debit_ils'));
    }

    public function totalCredit(): string
    {
        return Money::sum($this->lines->pluck('credit_ils'));
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', JournalStatus::Posted->value);
    }

    /**
     * Entries that count toward GL balances: everything except drafts. A
     * reversed entry stays in the ledger — it is offset by its reversal (both
     * are real postings that net to zero), never silently dropped.
     */
    public function scopeInLedger(Builder $query): Builder
    {
        return $query->whereIn('status', [JournalStatus::Posted->value, JournalStatus::Reversed->value]);
    }
}
