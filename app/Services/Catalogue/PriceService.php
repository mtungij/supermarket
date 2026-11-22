<?php

namespace App\Services\Catalogue;

use App\Exceptions\BusinessRuleException;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductUnit;
use App\Models\User;
use App\Services\Support\Authorizer;
use App\Support\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Effective-dated selling prices.
 *
 * Resolution order for (product, unit, branch, at):
 *   1. latest branch-specific price for that unit with effective_from <= at
 *   2. latest company-wide price for that unit
 *   3. base unit only: products.selling_price
 *   4. non-base unit with no price: resolved base price x conversion factor
 */
class PriceService
{
    public function __construct(private readonly Authorizer $authorizer) {}

    public function setPrice(Product $product, ProductUnit $productUnit, string $price, ?Branch $branch, User $actor, ?CarbonInterface $effectiveFrom = null, ?string $notes = null): ProductPrice
    {
        $this->authorizer->permission($actor, 'products.prices.manage');

        if ($branch !== null) {
            $this->authorizer->branch($actor, $branch);
        }

        if ($productUnit->product_id !== $product->id || ! $productUnit->is_active || ! $productUnit->can_sell) {
            throw BusinessRuleException::make('unit', __('Select a sellable unit of this product.'));
        }

        if ($branch !== null && $branch->company_id !== $product->company_id) {
            throw BusinessRuleException::make('branch', __('Select a valid branch.'));
        }

        if (! Decimal::isValid($price) || Decimal::scaleOf($price) > Decimal::MONEY || Decimal::isNegative($price)) {
            throw BusinessRuleException::make('price', __('Enter a valid price (up to 2 decimal places).'));
        }

        $effectiveFrom ??= now();

        if ($effectiveFrom->lt(now()->subMinute())) {
            throw BusinessRuleException::make('effective_from', __('Prices cannot be back-dated.'));
        }

        return DB::transaction(function () use ($product, $productUnit, $price, $branch, $actor, $effectiveFrom, $notes) {
            $record = new ProductPrice;
            $record->forceFill([
                'company_id' => $product->company_id,
                'product_id' => $product->id,
                'product_unit_id' => $productUnit->id,
                'branch_id' => $branch?->id,
                'price' => Decimal::normalize($price, Decimal::MONEY),
                'effective_from' => $effectiveFrom,
                'notes' => $notes,
                'created_by' => $actor->id,
            ])->save();

            // Keep the default (company-wide, base unit) price in sync once effective.
            if ($branch === null && $productUnit->is_base && $effectiveFrom->lte(now())) {
                Product::forCompany($product->company_id)->whereKey($product->id)
                    ->update(['selling_price' => $record->price, 'updated_by' => $actor->id]);
            }

            return $record;
        });
    }

    public function resolve(Product $product, ProductUnit $productUnit, ?Branch $branch = null, ?CarbonInterface $at = null): PriceQuote
    {
        $at ??= now();

        $find = fn (?int $branchId) => ProductPrice::forCompany($product->company_id)
            ->where('product_id', $product->id)
            ->where('product_unit_id', $productUnit->id)
            ->when($branchId === null, fn ($q) => $q->whereNull('branch_id'), fn ($q) => $q->where('branch_id', $branchId))
            ->where('effective_from', '<=', $at)
            ->orderByDesc('effective_from')->orderByDesc('id')
            ->first();

        if ($branch !== null && ($record = $find($branch->id)) !== null) {
            return new PriceQuote($record->price, $productUnit->id, $productUnit->factor, $branch->id, $record->id, 'branch_unit');
        }

        if (($record = $find(null)) !== null) {
            return new PriceQuote($record->price, $productUnit->id, $productUnit->factor, null, $record->id, 'company_unit');
        }

        if ($productUnit->is_base) {
            return new PriceQuote(Decimal::normalize($product->selling_price, Decimal::MONEY), $productUnit->id, $productUnit->factor, null, null, 'default_base');
        }

        $base = $this->resolve($product, $product->baseProductUnit, $branch, $at);

        return new PriceQuote(
            Decimal::round(Decimal::mul($base->price, $productUnit->factor), Decimal::MONEY),
            $productUnit->id, $productUnit->factor, $base->branchId, null, 'derived_from_base',
        );
    }
}
