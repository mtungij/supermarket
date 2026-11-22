<?php

namespace App\Enums;

/** draft → pending_approval → posted | rejected ; draft|pending → cancelled */
enum StockAdjustmentStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Posted = 'posted';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
