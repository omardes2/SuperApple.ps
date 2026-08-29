<?php

namespace App\Models;

use App\Enums\FinancialAccountType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A cash box, bank account or card. Each is backed by a GL account; its live
 * balance is DERIVED from posted journal lines (never an editable field).
 */
class FinancialAccount extends Model
{
    use Auditable;

    protected $fillable = [
        'name', 'type', 'currency', 'gl_account_id', 'bank_name', 'account_number',
        'iban', 'opening_balance', 'opening_balance_date', 'is_active', 'notes',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'type' => FinancialAccountType::class,
        'opening_balance' => 'decimal:2',
        'opening_balance_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'gl_account_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
