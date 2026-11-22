<?php

namespace App\Enums;

enum ProductType: string
{
    /** Sold in whole units (pieces, packs). Base unit must be a count unit. */
    case Standard = 'standard';

    /** Sold by weight (kg/g). Base unit must be a mass unit. */
    case Weighed = 'weighed';

    /** Sold by a continuous measure (litres, metres, kilograms). */
    case VariableMeasurement = 'variable_measurement';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standard product',
            self::Weighed => 'Weighed product',
            self::VariableMeasurement => 'Variable measurement product',
        };
    }

    /** @return list<UnitDimension> */
    public function allowedBaseDimensions(): array
    {
        return match ($this) {
            self::Standard => [UnitDimension::Count],
            self::Weighed => [UnitDimension::Mass],
            self::VariableMeasurement => [UnitDimension::Mass, UnitDimension::Volume, UnitDimension::Length],
        };
    }

    /** Standard products are counted in whole base units. */
    public function requiresWholeBaseQuantities(): bool
    {
        return $this === self::Standard;
    }
}
