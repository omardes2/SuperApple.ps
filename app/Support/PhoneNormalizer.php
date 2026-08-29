<?php

namespace App\Support;

/**
 * Normalises a raw phone/WhatsApp number to an E.164-ish "+<digits>" form.
 * The default country code is supplied by the caller (read from Settings) — it
 * is never hard-coded here. Invalid or too-short/long numbers return null so
 * the caller can refuse to send rather than dispatch to a bad destination.
 */
final class PhoneNormalizer
{
    public static function normalize(?string $raw, ?string $defaultCountryCode = null): ?string
    {
        if ($raw === null) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $hasIntl = str_starts_with($raw, '+') || str_starts_with($raw, '00');
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($raw, '00')) {
            $digits = substr($digits, 2); // drop the international "00" prefix
        }

        if (! $hasIntl) {
            $cc = $defaultCountryCode ? preg_replace('/\D+/', '', $defaultCountryCode) : null;

            if (str_starts_with($digits, '0')) {
                $digits = ltrim($digits, '0');
                if ($cc === null || $cc === '') {
                    return null; // national number but no country code to attach
                }
                $digits = $cc.$digits;
            } elseif ($cc !== null && $cc !== '' && ! str_starts_with($digits, $cc) && strlen($digits) <= 9) {
                // A bare local number without a trunk 0 → attach the country code.
                $digits = $cc.$digits;
            }
        }

        // E.164 allows at most 15 digits; anything under 8 is not a real number.
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return null;
        }

        return '+'.$digits;
    }

    public static function isValid(?string $raw, ?string $defaultCountryCode = null): bool
    {
        return self::normalize($raw, $defaultCountryCode) !== null;
    }
}
