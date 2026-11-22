<?php

namespace App\Enums;

enum ReturnDisposition: string
{
    /** Fit for resale: back into a sellable location. */
    case Restock = 'restock';
    /** Damaged: into the non-sellable quarantine location. */
    case Damaged = 'damaged';
    /** Expired: into the non-sellable quarantine location. */
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Restock => 'Fit for resale (restock)',
            self::Damaged => 'Damaged',
            self::Expired => 'Expired',
        };
    }

    public function isSellable(): bool
    {
        return $this === self::Restock;
    }
}
