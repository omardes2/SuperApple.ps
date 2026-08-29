<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A chart-of-accounts node. Leaf accounts receive postings; parent accounts
 * group them. System accounts are protected from deletion and type changes.
 */
class Account extends Model
{
    use Auditable;

    protected $table = 'chart_of_accounts';

    protected $fillable = [
        'code', 'name', 'parent_id', 'account_type', 'normal_balance',
        'is_system', 'is_active', 'allow_manual_posting', 'description',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'account_type' => AccountType::class,
        'normal_balance' => NormalBalance::class,
        'is_system' => 'boolean',
        'is_active' => 'boolean',
        'allow_manual_posting' => 'boolean',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'account_id');
    }

    public function isParent(): bool
    {
        return $this->children()->exists();
    }

    /** Any posting (system or manual) may only hit an active leaf account. */
    public function canReceivePosting(): bool
    {
        return $this->is_active && ! $this->isParent();
    }

    /** Manual journals additionally require allow_manual_posting. */
    public function canReceiveManualPosting(): bool
    {
        return $this->canReceivePosting() && $this->allow_manual_posting;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePostable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereDoesntHave('children');
    }
}
