<?php

namespace App\Services;

use App\Models\ExchangeRate;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Manages the USD→ILS rate table. There is one authoritative rate per day per
 * currency pair; re-saving the same day updates that rate (audited) rather than
 * creating a conflicting duplicate. Rates already snapshotted into issued
 * invoices are never affected by later edits here.
 */
class ExchangeRateService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Create or update the rate for a given day. `rate` must be > 0.
     *
     * @param  array<string,mixed>  $data
     */
    public function set(array $data): ExchangeRate
    {
        $base = $data['base_currency'] ?? 'USD';
        $quote = $data['quote_currency'] ?? 'ILS';
        $rate = Money::rate($data['rate'] ?? 0);

        if (Money::isZeroOrNegative($rate)) {
            throw new RuntimeException('سعر الصرف يجب أن يكون أكبر من صفر.');
        }

        $existing = ExchangeRate::query()
            ->whereDate('rate_date', $data['rate_date'])
            ->where('base_currency', $base)
            ->where('quote_currency', $quote)
            ->first();

        if ($existing) {
            $old = $existing->rate;
            $existing->update([
                'rate' => $rate,
                'source' => $data['source'] ?? $existing->source,
                'notes' => $data['notes'] ?? $existing->notes,
                'updated_by' => Auth::id(),
            ]);

            if ((string) $old !== (string) $existing->rate) {
                $this->audit->log('exchange_rate_changed', $existing, 'ExchangeRates',
                    old: ['rate' => $old], new: ['rate' => $existing->rate],
                    description: 'تعديل سعر صرف (لا يؤثر على الفواتير الصادرة سابقاً)');
            }

            return $existing;
        }

        $created = ExchangeRate::create([
            'rate_date' => $data['rate_date'],
            'base_currency' => $base,
            'quote_currency' => $quote,
            'rate' => $rate,
            'source' => $data['source'] ?? 'manual',
            'notes' => $data['notes'] ?? null,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        $this->audit->log('exchange_rate_created', $created, 'ExchangeRates',
            new: ['rate' => $created->rate], description: 'إضافة سعر صرف');

        return $created;
    }

    /**
     * Suggested rate for a document dated `$date`: the latest rate whose
     * rate_date is on or before it.
     */
    public function suggestedRate(string $date): ?string
    {
        return ExchangeRate::latestFor($date)?->rate;
    }
}
