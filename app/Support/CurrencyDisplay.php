<?php

namespace App\Support;

use App\Services\ExchangeRateService;
use App\Services\Settings;

/**
 * Display-only currency helper: resolves the estimate rate used to show a
 * secondary "≈ X ₪" under a USD amount when the amount has no document rate of
 * its own (services, draft quotations, etc.).
 *
 * Registered as a singleton so the latest/default rate is resolved ONCE per
 * request — a table of 100 rows never triggers 100 rate queries. Documents that
 * carry their own exchange_rate pass it explicitly and never touch this.
 *
 * This changes nothing about accounting: the official value stays USD; the ILS
 * line is a presentation estimate only.
 */
class CurrencyDisplay
{
    private bool $resolved = false;

    private ?string $latestRate = null;

    public function __construct(
        private readonly ExchangeRateService $rates,
        private readonly Settings $settings,
    ) {}

    /**
     * The best available USD→ILS rate for an amount with no rate of its own:
     * the latest published rate, else the configured default, else null.
     */
    public function latestOrDefaultRate(): ?string
    {
        if ($this->resolved) {
            return $this->latestRate;
        }
        $this->resolved = true;

        $rate = $this->rates->suggestedRate(now()->toDateString());
        if ($rate === null || $rate === '') {
            $rate = $this->settings->get('finance', 'default_exchange_rate');
        }

        return $this->latestRate = ($rate !== null && $rate !== '' ? (string) $rate : null);
    }

    /** ILS equivalent of a USD amount at a given rate, or null when no valid rate. */
    public function ilsFor(int|string|float|null $usd, int|string|float|null $rate): ?string
    {
        if ($rate === null || $rate === '' || ! Money::isPositive($rate)) {
            return null;
        }

        return Money::convertUsdToIls($usd ?? 0, $rate);
    }

    /** ILS equivalent using the latest/default estimate rate (or null). */
    public function estimatedIls(int|string|float|null $usd): ?string
    {
        return $this->ilsFor($usd, $this->latestOrDefaultRate());
    }
}
