<?php

namespace App\Services\Inventory;

use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidDocumentStateException;
use App\Models\Company;
use App\Models\InventoryCost;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Support\Decimal;
use App\Support\LockedRow;
use App\Support\Tenancy\TenantViolationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * THE ONLY code that changes stock. For every posting, inside the
 * caller's transaction, it:
 *
 *   1. verifies company / branch / location / product / batch ownership
 *   2. locks the branch cost row (inventory_costs) FOR UPDATE
 *   3. locks the location balance row (stock_balances) FOR UPDATE
 *   4. refuses to go negative (unless the company allows it)
 *   5. updates the weighted-average cost (inbound only)
 *   6. writes the balance and an immutable stock_movements row
 *
 * Lock order is always cost row -> balance row, and callers post lines
 * sorted by product id, so concurrent postings cannot deadlock on each
 * other. The unique (source_type, source_id, type) index on
 * stock_movements makes double posting of the same source line
 * impossible even if a caller's own status checks were bypassed.
 */
class StockLedger
{
    public function post(StockPosting $posting): StockMovement
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Stock postings must run inside a database transaction.');
        }

        $this->guardOwnership($posting);

        $quantity = Decimal::normalize($posting->quantityBase, Decimal::QTY);

        if (! Decimal::isPositive($quantity)) {
            throw InvalidDocumentStateException::make('quantity', __('Stock quantities must be greater than zero.'));
        }

        $location = $posting->location;
        $product = $posting->product;
        $inbound = $posting->type->isInbound();

        $cost = $this->lockCostRow($location->company_id, $location->branch_id, $product->id);
        $balance = $this->lockBalanceRow($location->company_id, $location->branch_id, $location->id, $product->id, $posting->batch?->id);

        $branchQty = (string) $cost->quantity;
        $average = (string) $cost->average_cost;

        if ($inbound) {
            if ($posting->unitCost === null) {
                throw new LogicException('Inbound postings require a unit cost.');
            }

            $unitCost = Decimal::normalize($posting->unitCost, Decimal::COST);

            if (Decimal::isNegative($unitCost)) {
                throw InvalidDocumentStateException::make('unit_cost', __('Costs cannot be negative.'));
            }

            // Weighted average: (Q*A + q*c) / (Q + q). With no stock on hand
            // (or a negative balance) the incoming cost becomes the average.
            $average = Decimal::isPositive($branchQty)
                ? Decimal::round(Decimal::div(
                    Decimal::add(Decimal::mul($branchQty, $average), Decimal::mul($quantity, $unitCost)),
                    Decimal::add($branchQty, $quantity),
                ), Decimal::COST)
                : $unitCost;

            $signed = $quantity;
        } else {
            $available = (string) $balance->quantity;

            if (Decimal::cmp($available, $quantity) < 0 && ! $this->allowsNegativeStock($location->company_id)) {
                throw InsufficientStockException::make('quantity', __('Not enough stock of :product in :location: :available available, :requested requested.', [
                    'product' => $product->name,
                    'location' => $location->name,
                    'available' => Decimal::trim($available),
                    'requested' => Decimal::trim($quantity),
                ]));
            }

            $unitCost = Decimal::round($average, Decimal::COST);
            $signed = Decimal::neg($quantity);
        }

        $newBalance = Decimal::round(Decimal::add((string) $balance->quantity, $signed), Decimal::QTY);
        $newBranchQty = Decimal::round(Decimal::add($branchQty, $signed), Decimal::QTY);

        StockBalance::forCompany($location->company_id)->whereKey($balance->id)->update([
            'quantity' => $newBalance, 'updated_at' => now(),
        ]);

        InventoryCost::forCompany($location->company_id)->whereKey($cost->id)->update(array_filter([
            'quantity' => $newBranchQty,
            'average_cost' => $average,
            'last_cost' => $posting->type === StockMovementType::GoodsReceipt ? $unitCost : null,
            'updated_at' => now(),
        ], fn ($v) => $v !== null));

        $movement = new StockMovement;
        $movement->forceFill([
            'company_id' => $location->company_id,
            'branch_id' => $location->branch_id,
            'location_id' => $location->id,
            'product_id' => $product->id,
            'batch_id' => $posting->batch?->id,
            'type' => $posting->type,
            'quantity' => Decimal::round($signed, Decimal::QTY),
            'unit_cost' => $unitCost,
            'total_cost' => Decimal::round(Decimal::mul($signed, $unitCost), Decimal::COST),
            'balance_after' => $newBalance,
            'branch_quantity_after' => $newBranchQty,
            'average_cost_after' => $average,
            'product_unit_id' => $posting->productUnit?->id,
            'entered_quantity' => $posting->enteredQuantity,
            'conversion_factor' => $posting->conversionFactor,
            'source_type' => $posting->source->getMorphClass(),
            'source_id' => $posting->source->getKey(),
            'reference' => $posting->reference,
            'performed_by' => $posting->user?->id,
            'occurred_at' => $posting->occurredAt ?? now(),
        ]);

        try {
            $movement->save();
        } catch (UniqueConstraintViolationException) {
            throw InvalidDocumentStateException::make('document', __('This document line has already been posted to stock.'));
        }

        return $movement;
    }

    /**
     * Lock a product's branch cost row (creating it if missing). Every
     * posting for that product in that branch takes this lock first, so a
     * caller holding it may read balances, allocate (e.g. FEFO) and then
     * post without any concurrent change in between.
     */
    public function lockBranchProduct(int $companyId, int $branchId, int $productId): InventoryCost
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Stock locks must be taken inside a database transaction.');
        }

        return $this->lockCostRow($companyId, $branchId, $productId);
    }

    /** Current branch weighted-average cost per base unit (no lock). */
    public function averageCost(int $companyId, int $branchId, int $productId): string
    {
        return (string) (InventoryCost::forCompany($companyId)->where('branch_id', $branchId)
            ->where('product_id', $productId)->value('average_cost') ?? '0.0000');
    }

    private function guardOwnership(StockPosting $posting): void
    {
        $location = $posting->location;

        if ($posting->product->company_id !== $location->company_id) {
            throw new TenantViolationException('Product and location belong to different companies.');
        }

        if ($posting->batch !== null && $posting->batch->product_id !== $posting->product->id) {
            throw new TenantViolationException('Batch does not belong to the product.');
        }

        if ($posting->productUnit !== null && $posting->productUnit->product_id !== $posting->product->id) {
            throw new TenantViolationException('Unit does not belong to the product.');
        }

        if ($posting->source->getAttribute('company_id') !== null
            && (int) $posting->source->getAttribute('company_id') !== $location->company_id) {
            throw new TenantViolationException('Source document belongs to another company.');
        }

        if ($posting->product->requiresBatch() && $posting->batch === null) {
            throw InvalidDocumentStateException::make('batch', __(':product is batch/expiry tracked: select a batch.', ['product' => $posting->product->name]));
        }

        if (! $location->is_active) {
            throw InvalidDocumentStateException::make('location', __('The stock location :location is inactive.', ['location' => $location->name]));
        }
    }

    private function lockCostRow(int $companyId, int $branchId, int $productId): InventoryCost
    {
        return LockedRow::lockOrCreate(
            fn () => InventoryCost::forCompany($companyId)->where('branch_id', $branchId)->where('product_id', $productId),
            fn () => DB::table('inventory_costs')->insertOrIgnore([
                'company_id' => $companyId, 'branch_id' => $branchId, 'product_id' => $productId,
                'quantity' => 0, 'average_cost' => 0, 'last_cost' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]),
        );
    }

    private function lockBalanceRow(int $companyId, int $branchId, int $locationId, int $productId, ?int $batchId): StockBalance
    {
        return LockedRow::lockOrCreate(
            fn () => StockBalance::forCompany($companyId)->where('location_id', $locationId)->where('product_id', $productId)->where('batch_key', $batchId ?? 0),
            fn () => DB::table('stock_balances')->insertOrIgnore([
                'company_id' => $companyId, 'branch_id' => $branchId, 'location_id' => $locationId,
                'product_id' => $productId, 'batch_id' => $batchId, 'quantity' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]),
        );
    }

    private function allowsNegativeStock(int $companyId): bool
    {
        return (bool) data_get(Company::query()->find($companyId)?->settings, 'allow_negative_stock', false);
    }
}
