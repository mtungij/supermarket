<?php

namespace Tests\Concerns;

use App\Enums\LocationType;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\InventoryCost;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\StockBalance;
use App\Models\StockLocation;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\Catalogue\ProductService;
use App\Services\Catalogue\UnitConversionService;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Purchasing\SupplierService;

/**
 * Builds catalogue/purchasing fixtures THROUGH the domain services, so
 * tests exercise the same rules as production code. Requires
 * CreatesTenants and an authenticated tenant context for services that
 * check permissions (use actingAsTenant()).
 */
trait CreatesInventory
{
    protected function unit(Company $company, string $code): Unit
    {
        return Unit::forCompany($company)->where('code', $code)->firstOrFail();
    }

    protected function defaultCategory(Company $company): Category
    {
        return Category::forCompany($company)->orderBy('id')->firstOrFail();
    }

    protected function location(Branch $branch, LocationType $type = LocationType::MainStore): StockLocation
    {
        return StockLocation::forCompany($branch->company_id)->where('branch_id', $branch->id)->where('type', $type->value)->firstOrFail();
    }

    protected function makeProduct(Company $company, User $actor, array $overrides = []): Product
    {
        return app(ProductService::class)->create($company, array_merge([
            'name' => 'Product '.fake()->unique()->numberBetween(1000, 99999),
            'category_id' => $this->defaultCategory($company)->id,
            'base_unit_id' => $this->unit($company, 'pc')->id,
            'product_type' => 'standard',
            'tax_class' => 'standard',
            'purchase_cost' => '1000',
            'selling_price' => '1500',
        ], $overrides), $actor);
    }

    /** Adds a packaging unit, e.g. carton = 24 pieces. */
    protected function packaging(Product $product, string $code, string $factor): ProductUnit
    {
        return app(UnitConversionService::class)->setUnit($product, $this->unit(Company::find($product->company_id), $code), $factor);
    }

    protected function baseUnitOf(Product $product): ProductUnit
    {
        return ProductUnit::forCompany($product->company_id)->where('product_id', $product->id)->where('is_base', true)->firstOrFail();
    }

    protected function makeSupplier(Company $company, string $name = 'Bakhresa Distributors'): Supplier
    {
        return app(SupplierService::class)->save($company, null, ['name' => $name, 'phone' => '+255 700 000 000']);
    }

    /**
     * @param  list<array{0: Product, 1: ProductUnit, 2: string, 3: string}>  $lines  [product, unit, qty, unit cost]
     */
    protected function approvedOrder(Company $company, Branch $branch, Supplier $supplier, array $lines, User $actor): PurchaseOrder
    {
        $service = app(PurchaseOrderService::class);

        $order = $service->createDraft($company, [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'order_date' => today()->toDateString(),
        ], array_map(fn ($l) => [
            'product_id' => $l[0]->id, 'product_unit_id' => $l[1]->id, 'quantity' => $l[2], 'unit_cost' => $l[3], 'tax_rate' => $l[4] ?? '0',
        ], $lines), $actor);

        $service->submit($order, $actor);

        return $service->approve($order, $actor);
    }

    /** @param list<array<string, mixed>> $lines */
    protected function receiveGoods(PurchaseOrder $order, array $lines, User $actor, ?StockLocation $location = null): GoodsReceipt
    {
        $location ??= StockLocation::forCompany($order->company_id)->where('branch_id', $order->branch_id)
            ->where('type', LocationType::ReceivingArea->value)->firstOrFail();

        return app(GoodsReceiptService::class)->receive($order, [
            'location_id' => $location->id,
            'received_date' => today()->toDateString(),
            'supplier_reference' => 'INV-'.fake()->numberBetween(1000, 9999),
        ], $lines, $actor);
    }

    protected function onHand(StockLocation $location, Product $product, ?int $batchId = null): string
    {
        return (string) (StockBalance::forCompany($location->company_id)->where('location_id', $location->id)
            ->where('product_id', $product->id)->where('batch_key', $batchId ?? 0)->value('quantity') ?? '0.0000');
    }

    protected function branchCost(Branch $branch, Product $product): ?InventoryCost
    {
        return InventoryCost::forCompany($branch->company_id)->where('branch_id', $branch->id)->where('product_id', $product->id)->first();
    }
}
