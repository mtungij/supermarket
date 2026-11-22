<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Normalised comparison key for names that must be unique per company
 * (categories, brands, suppliers): trimmed, internal whitespace collapsed,
 * lower-cased and accents folded, so "  Azam  " and "azam" collide.
 * Punctuation is kept: "Coca-Cola" and "Coca Cola" remain distinct.
 */
final class NameKey
{
    public static function make(string $name): string
    {
        $name = preg_replace('/\s+/u', ' ', trim($name)) ?? '';

        return Str::limit(mb_strtolower(Str::ascii($name)), 150, '');
    }

    public static function clean(string $name): string
    {
        return preg_replace('/\s+/u', ' ', trim($name)) ?? '';
    }
}
