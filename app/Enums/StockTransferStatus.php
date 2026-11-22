<?php

namespace App\Enums;

/**
 * draft → in_transit (dispatched: transfer_out posted at source)
 *       → received   (transfer_in posted at destination)
 * Same-branch transfers move directly draft → received.
 * draft → cancelled.
 */
enum StockTransferStatus: string
{
    case Draft = 'draft';
    case InTransit = 'in_transit';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::InTransit => 'In transit',
            self::Received => 'Received',
            self::Cancelled => 'Cancelled',
        };
    }
}
