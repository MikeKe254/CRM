<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Normalise a raw phone string to a 12-digit Kenyan MSISDN (254xxxxxxxxx).
 *
 * Accepts:
 *   0712345678   → 254712345678
 *   712345678    → 254712345678
 *   254712345678 → 254712345678
 *   +254712345678 → 254712345678
 *
 * Returns null for anything that cannot be resolved.
 */
final class PhoneNormalizer
{
    public static function normalize(string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if ($digits === null || $digits === '') {
            return null;
        }

        // 07xxxxxxxx → 254xxxxxxx
        if (strlen($digits) === 10 && $digits[0] === '0') {
            $digits = '254' . substr($digits, 1);
        }

        // 7xxxxxxxx or 1xxxxxxxx (9 digits) → 254xxxxxxx
        if (strlen($digits) === 9 && ($digits[0] === '7' || $digits[0] === '1')) {
            $digits = '254' . $digits;
        }

        if (strlen($digits) !== 12 || !str_starts_with($digits, '254')) {
            return null;
        }

        return $digits;
    }
}
