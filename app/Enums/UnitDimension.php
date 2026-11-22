<?php

namespace App\Enums;

/**
 * Physical dimension of a unit. Count units (piece, pack, carton, ...) have
 * NO global conversion: their factor is defined per product. Measurement
 * units (mass/volume/length) convert by a fixed physical ratio.
 */
enum UnitDimension: string
{
    case Count = 'count';
    case Mass = 'mass';
    case Volume = 'volume';
    case Length = 'length';

    public function isMeasurement(): bool
    {
        return $this !== self::Count;
    }

    public function label(): string
    {
        return match ($this) {
            self::Count => 'Count / packaging',
            self::Mass => 'Mass',
            self::Volume => 'Volume',
            self::Length => 'Length',
        };
    }
}
