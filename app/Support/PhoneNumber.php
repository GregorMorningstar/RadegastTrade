<?php

namespace App\Support;

/**
 * Phone numbers are stored in international form (+48123456789) so they can be compared regardless
 * of spaces and dashes. A 9-digit number is treated as Polish.
 */
class PhoneNumber
{
    public const PATTERN = '/^\+[1-9]\d{8,14}$/';

    public static function normalize(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $plus = str_starts_with(trim($value), '+');
        $digits = preg_replace('/\D/', '', $value);

        if (! $plus && str_starts_with($digits, '00')) {
            $plus = true;
            $digits = substr($digits, 2);
        }

        if (! $plus && strlen($digits) === 9) {
            return '+48'.$digits;
        }

        return ($plus ? '+' : '').$digits;
    }

    public static function isValid(string $normalized): bool
    {
        return preg_match(self::PATTERN, $normalized) === 1;
    }
}
