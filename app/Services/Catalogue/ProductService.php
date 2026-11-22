<?php

namespace App\Services\Catalogue;

use App\Enums\BarcodeType;
use App\Enums\ProductType;
use App\Enums\TaxClass;
use App\Exceptions\BusinessRuleException;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseOrderLine;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\Support\SubscriptionGuard;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

class ProductService
{
    public function __construct(
        private readonly SubscriptionGuard $subscription,
        private readonly BarcodeService $barcodes,
    ) {}

    /**
     * @param  array{
     *   name: string, sku?: ?string, description?: ?string, category_id: int, brand_id?: ?int,
     *   base_unit_id: int, product_type: string, tax_class: string, purchase_cost?: ?string,
     *   selling_price?: ?string, min_stock_level?: ?string, reorder_level?: ?string,
     *   track_expiry?: bool, track_batches?: bool, barcode?: ?string, barcode_type?: ?string
     * }  $data  Validated input. Related ids are re-checked against the company.
     */
    public function create(Company $company, array $data, User $actor): Product
    {
        $this->subscription->ensureWritable($company, 'name');

        return DB::transaction(function () use ($company, $data, $actor) {
            [$category, $brand, $baseUnit] = $this->relations($company, $data);
            $type = ProductType::from($data['product_type']);
            $this->ensureBaseUnitFits($type, $baseUnit);

            $product = new Product;
            $product->fill($this->attributes($data));
            $product->sku = $this->uniqueSku($company, $data['sku'] ?? null);
            $product->forceFill([
                'company_id' => $company->id,
                'category_id' => $category->id,
                'brand_id' => $brand?->id,
                'base_unit_id' => $baseUnit->id,
                'product_type' => $type,
                'purchase_cost' => $this->money($data['purchase_cost'] ?? '0', Decimal::COST, 'purchase_cost'),
                'selling_price' => $this->money($data['selling_price'] ?? '0', Decimal::MONEY, 'selling_price'),
                'is_active' => true,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            $base = new ProductUnit;
            $base->forceFill([
                'company_id' => $company->id, 'product_id' => $product->id, 'unit_id' => $baseUnit->id,
                'factor' => '1', 'is_base' => true, 'can_purchase' => true, 'can_sell' => true, 'is_active' => true,
            ])->save();

            if (filled($data['barcode'] ?? null)) {
                $this->barcodes->add($product, $data['barcode'], BarcodeType::tryFrom($data['barcode_type'] ?? '') ?? BarcodeType::Manufacturer, $base, true);
            }

            return $product;
        });
    }

    public function update(Product $product, array $data, User $actor): Product
    {
        $company = Company::query()->findOrFail($product->company_id);
        $this->subscription->ensureWritable($company, 'name');

        return DB::transaction(function () use ($company, $product, $data, $actor) {
            $product = Product::forCompany($company)->lockForUpdate()->findOrFail($product->id);
            [$category, $brand, $baseUnit] = $this->relations($company, $data);
            $type = ProductType::from($data['product_type']);

            $structuralChange = $baseUnit->id !== $product->base_unit_id || $type !== $product->product_type;

            if ($structuralChange && $this->hasTransactions($product)) {
                throw BusinessRuleException::make('base_unit_id', __('The base unit and product type cannot change after stock or purchase transactions exist.'));
            }

            if ($structuralChange) {
                $this->ensureBaseUnitFits($type, $baseUnit);
                ProductUnit::forCompany($company)->where('product_id', $product->id)->where('is_base', true)
                    ->update(['unit_id' => $baseUnit->id]);
            }

            if (($product->track_batches || $product->track_expiry) !== (($data['track_batches'] ?? false) || ($data['track_expiry'] ?? false))
                && StockMovement::forCompany($company)->where('product_id', $product->id)->exists()) {
                throw BusinessRuleException::make('track_batches', __('Batch and expiry tracking cannot be switched after stock movements exist.'));
            }

            $product->fill($this->attributes($data));
            $product->sku = $this->uniqueSku($company, $data['sku'] ?? $product->sku, $product->id);
            $product->forceFill([
                'category_id' => $category->id,
                'brand_id' => $brand?->id,
                'base_unit_id' => $baseUnit->id,
                'product_type' => $type,
                'updated_by' => $actor->id,
            ])->save();

            return $product;
        });
    }

    public function setActive(Product $product, bool $active, User $actor): void
    {
        $product->forceFill(['is_active' => $active, 'updated_by' => $actor->id])->save();
    }

    public function hasTransactions(Product $product): bool
    {
        return StockMovement::forCompany($product->company_id)->where('product_id', $product->id)->exists()
            || PurchaseOrderLine::forCompany($product->company_id)->where('product_id', $product->id)->exists();
    }

    private function attributes(array $data): array
    {
        return [
            'name' => trim($data['name']),
            'description' => $data['description'] ?? null,
            'tax_class' => TaxClass::from($data['tax_class']),
            'min_stock_level' => $this->money($data['min_stock_level'] ?? '0', Decimal::QTY, 'min_stock_level'),
            'reorder_level' => $this->money($data['reorder_level'] ?? '0', Decimal::QTY, 'reorder_level'),
            'track_expiry' => (bool) ($data['track_expiry'] ?? false),
            'track_batches' => (bool) ($data['track_batches'] ?? false),
        ];
    }

    /** @return array{0: Category, 1: ?Brand, 2: Unit} */
    private function relations(Company $company, array $data): array
    {
        $category = Category::forCompany($company)->where('is_active', true)->find($data['category_id'] ?? 0)
            ?? throw BusinessRuleException::make('category_id', __('Select a valid category.'));

        $brand = null;
        if (filled($data['brand_id'] ?? null)) {
            $brand = Brand::forCompany($company)->where('is_active', true)->find($data['brand_id'])
                ?? throw BusinessRuleException::make('brand_id', __('Select a valid brand.'));
        }

        $unit = Unit::forCompany($company)->where('is_active', true)->find($data['base_unit_id'] ?? 0)
            ?? throw BusinessRuleException::make('base_unit_id', __('Select a valid unit.'));

        return [$category, $brand, $unit];
    }

    private function ensureBaseUnitFits(ProductType $type, Unit $unit): void
    {
        if (! in_array($unit->dimension, $type->allowedBaseDimensions(), true)) {
            throw BusinessRuleException::make('base_unit_id', __(':type products need a base unit measured in :dimensions.', [
                'type' => __($type->label()),
                'dimensions' => collect($type->allowedBaseDimensions())->map(fn ($d) => __($d->label()))->join(', '),
            ]));
        }
    }

    private function uniqueSku(Company $company, ?string $sku, ?int $ignoreId = null): string
    {
        $sku = strtoupper(trim((string) $sku));

        if ($sku === '') {
            do {
                $sku = 'SKU-'.strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            } while (Product::forCompany($company)->where('sku', $sku)->exists());

            return $sku;
        }

        if (! preg_match('/^[A-Z0-9\-_.\/]{1,64}$/', $sku)) {
            throw BusinessRuleException::make('sku', __('SKUs may contain letters, digits and - _ . / (max 64).'));
        }

        $taken = Product::forCompany($company)->where('sku', $sku)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists();

        if ($taken) {
            throw BusinessRuleException::make('sku', __('This SKU is already used by another product.'));
        }

        return $sku;
    }

    private function money(string|int|null $value, int $scale, string $field): string
    {
        $value = (string) ($value ?? '0');
        $value = $value === '' ? '0' : str_replace(',', '', $value);

        if (! Decimal::isValid($value) || Decimal::isNegative($value) || Decimal::scaleOf($value) > $scale) {
            throw BusinessRuleException::make($field, __('Enter a valid non-negative amount.'));
        }

        return Decimal::normalize($value, $scale);
    }
}
