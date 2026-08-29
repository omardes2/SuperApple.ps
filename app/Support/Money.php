<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Decimal-safe money arithmetic for the whole system. Every financial figure
 * goes through brick/math BigDecimal — never native float — so no penny drifts.
 *
 * Rounding policy (documented in docs/CURRENCY.md and applied everywhere):
 *   - Rounding mode: HALF_UP.
 *   - Money scale (USD and ILS): 2 decimals.
 *   - Exchange-rate scale: 6 decimals.
 *   - Unit prices may carry up to 4 internal decimals; line/document amounts
 *     are rounded to 2 decimals per line, then summed.
 */
final class Money
{
    public const MONEY_SCALE = 2;

    public const RATE_SCALE = 6;

    public const PRICE_SCALE = 4;

    public static function of(int|string|float $value): BigDecimal
    {
        // Cast float to string first to avoid binary float noise.
        return BigDecimal::of(is_float($value) ? sprintf('%.8F', $value) : (string) $value);
    }

    /** Round a value to money scale (2dp, HALF_UP) and return it as a string. */
    public static function money(BigDecimal|int|string|float $value): string
    {
        $bd = $value instanceof BigDecimal ? $value : self::of($value);

        return (string) $bd->toScale(self::MONEY_SCALE, RoundingMode::HalfUp);
    }

    /** Round an exchange rate to rate scale (6dp). */
    public static function rate(BigDecimal|int|string|float $value): string
    {
        $bd = $value instanceof BigDecimal ? $value : self::of($value);

        return (string) $bd->toScale(self::RATE_SCALE, RoundingMode::HalfUp);
    }

    /**
     * Multiply two money-ish values, rounded to money scale.
     */
    public static function multiply(int|string|float $a, int|string|float $b): string
    {
        return self::money(self::of($a)->multipliedBy(self::of($b)));
    }

    /**
     * total_ils = total_usd × exchange_rate, rounded to money scale.
     */
    public static function convertUsdToIls(int|string|float $usd, int|string|float $rate): string
    {
        return self::money(self::of($usd)->multipliedBy(self::of($rate)));
    }

    /**
     * usd_equivalent = ils_amount ÷ exchange_rate, rounded to money scale.
     * A generous internal scale is used before the final HALF_UP rounding.
     */
    public static function convertIlsToUsd(int|string|float $ils, int|string|float $rate): string
    {
        $usd = self::of($ils)->dividedBy(self::of($rate), self::MONEY_SCALE + 8, RoundingMode::HalfUp);

        return self::money($usd);
    }

    /** a − b at money scale. */
    public static function subtract(int|string|float $a, int|string|float $b): string
    {
        return self::money(self::of($a)->minus(self::of($b)));
    }

    /** a + b at money scale. */
    public static function add(int|string|float $a, int|string|float $b): string
    {
        return self::money(self::of($a)->plus(self::of($b)));
    }

    /** Sum a list of money values at money scale. */
    public static function sum(iterable $values): string
    {
        $total = BigDecimal::zero();
        foreach ($values as $v) {
            $total = $total->plus(self::of($v));
        }

        return self::money($total);
    }

    public static function isGreaterThan(int|string|float $a, int|string|float $b): bool
    {
        return self::of(self::money($a))->isGreaterThan(self::money($b));
    }

    /** Absolute difference at money scale (always >= 0). */
    public static function absDiff(int|string|float $a, int|string|float $b): string
    {
        return self::money(self::of($a)->minus(self::of($b))->abs());
    }

    public static function isPositive(int|string|float $value): bool
    {
        return self::of($value)->isGreaterThan(0);
    }

    public static function isZeroOrNegative(int|string|float $value): bool
    {
        return ! self::isPositive($value);
    }

    /**
     * Compare two money values for equality at money scale.
     */
    public static function equals(int|string|float $a, int|string|float $b): bool
    {
        return self::of(self::money($a))->isEqualTo(self::money($b));
    }
}
