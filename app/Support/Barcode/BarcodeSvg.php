<?php

namespace App\Support\Barcode;

use InvalidArgumentException;

/**
 * Dependency-free barcode renderer for printable labels.
 * - EAN-13 for valid 13-digit GTINs (UPC-A is rendered as EAN-13 with a leading 0)
 * - Code 128 (set B) for everything else (internal codes, SKUs, GTIN-8/14)
 */
final class BarcodeSvg
{
    private const L = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];

    private const G = ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111'];

    private const R = ['1110010', '1100110', '1101100', '1000010', '1011100', '1001110', '1010000', '1000100', '1001000', '1110100'];

    private const PARITY = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];

    /** Code 128 bar/space widths for values 0-106 (106 = stop). */
    public const CODE128 = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    /** @return string module pattern of 0/1 characters */
    public static function modules(string $code): string
    {
        if (strlen($code) === 12 && Gtin::isValid($code)) {
            $code = '0'.$code;
        }

        return strlen($code) === 13 && Gtin::isValid($code) ? self::ean13($code) : self::code128($code);
    }

    public static function ean13(string $code): string
    {
        $parity = self::PARITY[(int) $code[0]];
        $bits = '101';

        for ($i = 1; $i <= 6; $i++) {
            $bits .= ($parity[$i - 1] === 'L' ? self::L : self::G)[(int) $code[$i]];
        }

        $bits .= '01010';

        for ($i = 7; $i <= 12; $i++) {
            $bits .= self::R[(int) $code[$i]];
        }

        return $bits.'101';
    }

    public static function code128(string $code): string
    {
        if ($code === '' || preg_match('/[^\x20-\x7E]/', $code)) {
            throw new InvalidArgumentException('Code 128B supports printable ASCII only.');
        }

        $values = [104]; // Start B
        foreach (str_split($code) as $char) {
            $values[] = ord($char) - 32;
        }

        $checksum = 104;
        foreach (array_slice($values, 1) as $position => $value) {
            $checksum += ($position + 1) * $value;
        }
        $values[] = $checksum % 103;
        $values[] = 106; // Stop

        $bits = '';
        foreach ($values as $value) {
            foreach (str_split(self::CODE128[$value]) as $i => $width) {
                $bits .= str_repeat($i % 2 === 0 ? '1' : '0', (int) $width);
            }
        }

        return $bits;
    }

    /**
     * Physically sized SVG for thermal labels: every module is exactly
     * $moduleMm wide and the bars $heightMm tall, so the printed size does
     * not depend on browser scaling. Quiet zones of $quiet modules on each
     * side (EAN-13 needs >= 11 left / 7 right; Code 128 >= 10).
     *
     * $maxWidthMm caps the total width (long Code 128 values): the module is
     * reduced to fit, never below what is returned in $moduleMm by reference.
     */
    public static function svgMm(string $code, float $heightMm, float &$moduleMm, float $maxWidthMm, int $quiet = 11): string
    {
        $bits = str_repeat('0', $quiet).self::modules($code).str_repeat('0', $quiet);
        $count = strlen($bits);
        $moduleMm = min($moduleMm, floor($maxWidthMm / $count * 1000) / 1000);
        $rects = '';
        $run = 0;

        // merge adjacent dark modules into one rect: fewer edges, crisper print
        foreach (str_split($bits.'0') as $i => $bit) {
            if ($bit === '1') {
                $run++;

                continue;
            }
            if ($run > 0) {
                $rects .= sprintf('<rect x="%d" y="0" width="%d" height="1"/>', $i - $run, $run);
                $run = 0;
            }
        }

        return sprintf('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d 1" preserveAspectRatio="none" width="%.3Fmm" height="%.2Fmm" role="img" aria-label="%s" fill="#000" shape-rendering="crispEdges">%s</svg>',
            $count, $count * $moduleMm, $heightMm, e($code), $rects);
    }

    /** Inline SVG (black bars, quiet zones included). */
    public static function svg(string $code, int $height = 60, float $module = 1.6): string
    {
        $bits = str_repeat('0', 10).self::modules($code).str_repeat('0', 10);
        $width = strlen($bits) * $module;
        $rects = '';

        foreach (str_split($bits) as $i => $bit) {
            if ($bit === '1') {
                $rects .= sprintf('<rect x="%.2F" y="0" width="%.2F" height="%d"/>', $i * $module, $module, $height);
            }
        }

        return sprintf('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %.2F %d" width="100%%" height="%d" role="img" aria-label="%s" fill="#000" shape-rendering="crispEdges">%s</svg>',
            $width, $height, $height, e($code), $rects);
    }
}
