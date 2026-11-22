<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact decimal arithmetic on numeric strings (bcmath). Floats are never
 * used for quantities, costs or money.
 *
 * Scales used across the application:
 *   QTY   = 4  quantities (base and entered units)
 *   COST  = 4  unit costs and weighted-average costs
 *   MONEY = 2  monetary amounts (line totals, document totals)
 *   FACTOR= 6  unit conversion factors
 *
 * Rounding is ROUND_HALF_UP (away from zero on .5), applied explicitly
 * via round(). Intermediate results use INTERNAL_SCALE.
 */
final class Decimal
{
    public const QTY = 4;

    public const COST = 4;

    public const MONEY = 2;

    public const FACTOR = 6;

    private const INTERNAL_SCALE = 12;

    public static function normalize(string|int|null $value, int $scale = self::INTERNAL_SCALE): string
    {
        $value = trim((string) ($value ?? '0'));

        if ($value === '' || ! preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $value)) {
            throw new InvalidArgumentException("Invalid decimal value [{$value}].");
        }

        return self::round($value, $scale);
    }

    public static function isValid(mixed $value): bool
    {
        return (is_string($value) || is_int($value))
            && preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', trim((string) $value)) === 1;
    }

    public static function add(string $a, string $b, int $scale = self::INTERNAL_SCALE): string
    {
        return bcadd($a, $b, $scale);
    }

    public static function sub(string $a, string $b, int $scale = self::INTERNAL_SCALE): string
    {
        return bcsub($a, $b, $scale);
    }

    public static function mul(string $a, string $b, int $scale = self::INTERNAL_SCALE): string
    {
        return bcmul($a, $b, $scale);
    }

    public static function div(string $a, string $b, int $scale = self::INTERNAL_SCALE): string
    {
        if (self::isZero($b)) {
            throw new InvalidArgumentException('Division by zero.');
        }

        return bcdiv($a, $b, $scale);
    }

    /** Round half up (away from zero). */
    public static function round(string $value, int $scale): string
    {
        $value = ltrim($value, '+');
        $negative = str_starts_with($value, '-');
        $offset = '0.'.str_repeat('0', $scale).'5';

        $rounded = $negative
            ? bcsub($value, $offset, $scale)
            : bcadd($value, $offset, $scale);

        // Avoid "-0.00".
        return self::isZero($rounded) ? bcadd('0', '0', $scale) : $rounded;
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp($a, $b, self::INTERNAL_SCALE);
    }

    public static function isZero(string $value): bool
    {
        return bccomp($value, '0', self::INTERNAL_SCALE) === 0;
    }

    public static function isPositive(string $value): bool
    {
        return bccomp($value, '0', self::INTERNAL_SCALE) === 1;
    }

    public static function isNegative(string $value): bool
    {
        return bccomp($value, '0', self::INTERNAL_SCALE) === -1;
    }

    public static function isWhole(string $value): bool
    {
        return bccomp($value, bcadd($value, '0', 0), self::INTERNAL_SCALE) === 0;
    }

    /** Number of significant fractional digits. */
    public static function scaleOf(string $value): int
    {
        $value = rtrim(rtrim(self::normalize($value), '0'), '.');
        $pos = strpos($value, '.');

        return $pos === false ? 0 : strlen($value) - $pos - 1;
    }

    public static function max(string $a, string $b): string
    {
        return self::cmp($a, $b) >= 0 ? $a : $b;
    }

    public static function neg(string $value): string
    {
        return bcmul($value, '-1', self::INTERNAL_SCALE);
    }

    public static function abs(string $value): string
    {
        return self::isNegative($value) ? self::neg($value) : $value;
    }

    /** Human display without trailing zeros (quantities). */
    public static function trim(string|int|null $value): string
    {
        $value = (string) ($value ?? '0');

        if (! str_contains($value, '.')) {
            return $value;
        }

        $value = rtrim(rtrim($value, '0'), '.');

        return $value === '-0' ? '0' : $value;
    }

    /** Money display with thousands separators (no float conversion). */
    public static function format(string|int|null $value, int $decimals = self::MONEY): string
    {
        $rounded = self::round((string) ($value ?? '0'), $decimals);
        $negative = str_starts_with($rounded, '-');
        [$int, $frac] = array_pad(explode('.', ltrim($rounded, '-')), 2, '');
        $int = strrev(implode(',', str_split(strrev($int), 3)));

        return ($negative ? '-' : '').$int.($decimals > 0 ? '.'.$frac : '');
    }
}
