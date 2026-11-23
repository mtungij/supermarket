<?php

namespace App\Enums;

/** draft (editable) -> posted (irreversible) ; draft -> cancelled */
enum OpeningStockStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
