<?php

namespace App\Services\Catalogue;

use App\Models\Branch;
use App\Models\InventoryCost;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Support\Decimal;

/**
 * Suggested values for editable document lines (opening stock, purchases),
 * always per the line's unit. Suggestions only: the user sees and can
 * change them, and the services validate and recompute on save.
 *
 *  buying cost  = branch weighted-average cost, else latest receipt cost,
 *                 else the product's reference buying price (x unit factor)
 *  selling price = current effective price for that unit (PriceService)
 */
class PricePrefill
{
    public function __construct(private readonly PriceService $prices) {}

    /** @return array{0: ?string, 1: string} [cost per unit or null, source: average|last|reference|none] */
    public function buyingCost(Product $product, ProductUnit $unit, ?Branch $branch): array
    {
        $average = $branch ? (string) (InventoryCost::forCompany($product->company_id)->where('branch_id', $branch->id)
            ->where('product_id', $product->id)->value('average_cost') ?? '0') : '0';

        [$base, $source] = match (true) {
            Decimal::isPositive($average) => [$average, 'average'],
            Decimal::isPositive((string) $product->purchase_cost) => [(string) $product->purchase_cost, 'last'],
            $product->reference_buying_price !== null && Decimal::isPositive((string) $product->reference_buying_price) => [(string) $product->reference_buying_price, 'reference'],
            default => [null, 'none'],
        };

        return [$base === null ? null : Decimal::trim(Decimal::round(Decimal::mul($base, (string) $unit->factor), Decimal::COST)), $source];
    }

    public function sellingPrice(Product $product, ProductUnit $unit, ?Branch $branch): ?string
    {
        return $unit->can_sell ? Decimal::trim($this->prices->resolve($product, $unit, $branch)->price) : null;
    }
}
