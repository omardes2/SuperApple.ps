<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Unified presentation formatting for money, rates and dates. Keeps the UI
 * consistent: USD as `$1,250.00`, ILS as `1,250.00 ₪`, rates at 6 decimals.
 * Dates use the application timezone (config), never the browser clock, so
 * financial events read the same for everyone.
 */
final class Format
{
    public static function usd(int|string|float|null $value): string
    {
        return '$'.number_format((float) ($value ?? 0), 2);
    }

    public static function ils(int|string|float|null $value): string
    {
        return number_format((float) ($value ?? 0), 2).' ₪';
    }

    /** Format an amount by ISO currency code. */
    public static function money(int|string|float|null $value, string $currency = 'USD'): string
    {
        return $currency === 'ILS' ? self::ils($value) : self::usd($value);
    }

    public static function rate(int|string|float|null $value): string
    {
        return number_format((float) ($value ?? 0), 6);
    }

    /** Percentage with one decimal, e.g. 12.5%. */
    public static function percent(int|string|float|null $value): string
    {
        return number_format((float) ($value ?? 0), 1).'%';
    }

    public static function date(mixed $value): string
    {
        if (blank($value)) {
            return '—';
        }

        return Carbon::parse($value)->format('Y-m-d');
    }

    public static function dateTime(mixed $value): string
    {
        if (blank($value)) {
            return '—';
        }

        return Carbon::parse($value)->format('Y-m-d H:i');
    }

    /**
     * A signed money string with an explicit sign and neutral wording — never
     * relying on colour alone to convey gain/loss/credit.
     */
    public static function signed(int|string|float|null $value, string $currency = 'USD'): string
    {
        $num = (float) ($value ?? 0);
        $sign = $num > 0 ? '+' : ($num < 0 ? '−' : '');

        return $sign.self::money(abs($num), $currency);
    }
}
