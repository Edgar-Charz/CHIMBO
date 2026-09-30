<?php

/**
 * Tanzanian mobile numbers.
 * Every accepted format is stored in one standard form (E.164): +2557XXXXXXXX or +2556XXXXXXXX.
 */
class Phone
{
    /**
     * Returns the number as +255XXXXXXXXX, or null if it is not a valid Tanzanian mobile number.
     * Accepts: 0712345678, 712345678, 255712345678, +255 712 345 678, +255-712-345-678.
     */
    public static function normalizeTz(string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', $phone);

        $local = match (true) {
            strlen($digits) === 12 && str_starts_with($digits, '255') => substr($digits, 3),
            strlen($digits) === 10 && str_starts_with($digits, '0')   => substr($digits, 1),
            strlen($digits) === 9                                      => $digits,
            default                                                    => null,
        };

        // Mobile numbers start with 6 or 7 after the country code
        if ($local === null || !preg_match('/^[67]\d{8}$/', $local)) {
            return null;
        }

        return '+255' . $local;
    }

    /** "+255712345678" → "+255 712 345 678" (for display). */
    public static function format(string $e164): string
    {
        return preg_replace('/^\+255(\d{3})(\d{3})(\d{3})$/', '+255 $1 $2 $3', $e164) ?? $e164;
    }
}
