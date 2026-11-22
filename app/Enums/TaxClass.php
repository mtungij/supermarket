<?php

namespace App\Enums;

enum TaxClass: string
{
    case Standard = 'standard';
    case ZeroRated = 'zero_rated';
    case Exempt = 'exempt';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standard rate (VAT)',
            self::ZeroRated => 'Zero-rated',
            self::Exempt => 'Exempt',
        };
    }

    /** Default rate in percent as a decimal string. */
    public function defaultRate(): string
    {
        return match ($this) {
            self::Standard => (string) config('inventory.tax_rates.standard', '18.00'),
            self::ZeroRated, self::Exempt => '0.00',
        };
    }
}
