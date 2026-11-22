<?php

namespace App\Enums;

enum AdjustmentReason: string
{
    case Damaged = 'damaged';
    case Expired = 'expired';
    case Lost = 'lost';
    case Found = 'found';
    case CountCorrection = 'count_correction';

    /** @return list<AdjustmentDirection> */
    public function allowedDirections(): array
    {
        return match ($this) {
            self::Damaged, self::Expired, self::Lost => [AdjustmentDirection::Out],
            self::Found => [AdjustmentDirection::In],
            self::CountCorrection => [AdjustmentDirection::In, AdjustmentDirection::Out],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Damaged => 'Damaged goods',
            self::Expired => 'Expired goods',
            self::Lost => 'Lost goods',
            self::Found => 'Found goods',
            self::CountCorrection => 'Stock count correction',
        };
    }
}
