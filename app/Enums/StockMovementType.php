<?php

namespace App\Enums;

/**
 * Every change to stock is one of these movements. There is no
 * shift-related movement: future sales post as `sale` movements linked
 * to company, branch, device, cashier and timestamp.
 */
enum StockMovementType: string
{
    case GoodsReceipt = 'goods_receipt';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    /** POS sale (outbound at weighted-average cost = COGS). No shift involved. */
    case Sale = 'sale';
    /** Customer return (inbound at the ORIGINAL sale cost). */
    case SaleReturn = 'sale_return';
    /** Initial physical stock that existed before the shop used the system (inbound at the confirmed opening cost). */
    case OpeningStock = 'opening_stock';

    public function isInbound(): bool
    {
        return in_array($this, [self::GoodsReceipt, self::AdjustmentIn, self::TransferIn, self::SaleReturn, self::OpeningStock], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::GoodsReceipt => 'Goods received',
            self::AdjustmentIn => 'Adjustment (in)',
            self::AdjustmentOut => 'Adjustment (out)',
            self::TransferOut => 'Transfer out',
            self::TransferIn => 'Transfer in',
            self::Sale => 'Sale',
            self::SaleReturn => 'Sale return',
            self::OpeningStock => 'Opening stock',
        };
    }
}
