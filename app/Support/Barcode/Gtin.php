<?php

namespace App\Support\Barcode;

/** GTIN (EAN-8, UPC-A, EAN-13, GTIN-14) check-digit helpers. */
final class Gtin
{
    public static function isGtin(string $code): bool
    {
        return preg_match('/^\d{8}$|^\d{12,14}$/', $code) === 1;
    }

    public static function isValid(string $code): bool
    {
        return self::isGtin($code) && self::checkDigit(substr($code, 0, -1)) === (int) substr($code, -1);
    }

    /** Check digit for the given payload (all digits except the last). */
    public static function checkDigit(string $payload): int
    {
        $sum = 0;

        foreach (array_reverse(str_split($payload)) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - ($sum % 10)) % 10;
    }
}
