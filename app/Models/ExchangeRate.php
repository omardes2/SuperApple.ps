<?php

namespace App\Models;

use App\Enums\ExchangeRateSource;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    use Auditable;

    protected $fillable = [
        'rate_date', 'base_currency', 'quote_currency', 'rate', 'source',
        'notes', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'rate_date' => 'date',
        'rate' => 'decimal:6',
        'source' => ExchangeRateSource::class,
    ];

    /**
     * The most recent USD→ILS rate effective on or before the given date.
     */
    public static function latestFor(string $date, string $base = 'USD', string $quote = 'ILS'): ?self
    {
        return static::query()
            ->where('base_currency', $base)
            ->where('quote_currency', $quote)
            ->whereDate('rate_date', '<=', $date)
            ->orderByDesc('rate_date')
            ->first();
    }

    public function scopeUsdIls(Builder $query): Builder
    {
        return $query->where('base_currency', 'USD')->where('quote_currency', 'ILS');
    }
}
