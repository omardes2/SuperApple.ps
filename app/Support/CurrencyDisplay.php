<?php

namespace App\Support;

/**
 * Display-only currency helper.
 *
 * Since the standalone exchange-rate module was retired, there is NO central /
 * latest / default rate anymore: a USD amount only shows a secondary "≈ X ₪"
 * line when it carries its OWN document exchange rate (an invoice or a payment).
 * An amount with no contextual rate — a service catalogue price, a running
 * balance — is shown as USD only, never an invented estimate.
 *
 * `estimatedIls()` therefore always returns null now (it makes no query), and
 * `<x-money :useLatest>` degrades to USD-only. `ilsFor()` stays for callers that
 * pass an explicit, real document rate. Accounting is untouched — this is
 * presentation only.
 */
class CurrencyDisplay
{
    /** ILS equivalent of a USD amount at a given (real, document) rate, or null. */
    public function ilsFor(int|string|float|null $usd, int|string|float|null $rate): ?string
    {
        if ($rate === null || $rate === '' || ! Money::isPositive($rate)) {
            return null;
        }

        return Money::convertUsdToIls($usd ?? 0, $rate);
    }

    /**
     * No central rate exists anymore, so there is no estimate to show. Kept for
     * backward-compatible call sites; always returns null (never queries).
     */
    public function estimatedIls(int|string|float|null $usd): ?string
    {
        return null;
    }

    /** No central/latest/default rate exists — always null. */
    public function latestOrDefaultRate(): ?string
    {
        return null;
    }
}
