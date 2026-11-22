<?php

namespace App\Enums;

enum LocationType: string
{
    case MainStore = 'main_store';
    case SalesFloor = 'sales_floor';
    case ReceivingArea = 'receiving_area';
    case Other = 'other';
    /** Damaged / expired returns awaiting disposal. Never sellable. */
    case Quarantine = 'quarantine';

    public function label(): string
    {
        return match ($this) {
            self::MainStore => 'Main Store',
            self::SalesFloor => 'Sales Floor',
            self::ReceivingArea => 'Receiving Area',
            self::Other => 'Other storage',
            self::Quarantine => 'Damaged / Quarantine',
        };
    }

    public function defaultCode(): string
    {
        return match ($this) {
            self::MainStore => 'MAIN',
            self::SalesFloor => 'FLOOR',
            self::ReceivingArea => 'RECV',
            self::Other => 'OTHER',
            self::Quarantine => 'QUAR',
        };
    }

    /**
     * Any location except Quarantine may be EXPLICITLY flagged as POS-sellable
     * by an authorised user. By default only the Sales Floor is sellable.
     */
    public function canBeSellable(): bool
    {
        return $this !== self::Quarantine;
    }
}
