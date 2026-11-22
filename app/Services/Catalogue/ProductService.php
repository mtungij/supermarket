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
use Illuminate\Validation\ValidationException;

class ProductService
{
    public function __construct(
        private readonly SubscriptionGuard $subscription,
        private readonly BarcodeService $barcodes,
        private readonly PriceService $prices,
        private readonly UnitConversionService $conversions,
    ) {}

    /**
     * @param  array{
     *   name: string, sku?: ?string, description?: ?string, category_id: int, brand_id?: ?int,
     *   base_unit_id: int, product_type: string, tax_class: string, purchase_cost?: ?string,
     *   selling_price?: ?string, min_stock_level?: ?string, reorder_level?: ?string,
     *   track_expiry?: bool, track_batches?: bool, barcode?: ?string, barcode_type?: ?string,
     *   reference_buying_price?: ?string, initial_price?: ?string, generate_barcode?: bool,
     *   extra_barcodes?: list<string>,
     *   packaging?: list<array{unit_id: int, factor?: ?string, barcode?: ?string, price?: ?string, can_purchase?: bool, can_sell?: bool}>
     * }  $data  Validated input. Related ids are re-checked against the company.
     *
     * One-step registration: product, base unit, barcodes, packaging units
     * and the initial selling price are written in ONE transaction, so a
     * failure anywhere leaves nothing behind. The initial price goes through
     * PriceService::setPrice() (price-history record + permission check);
     * no stock, GRN or cost is ever created here.
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
                'reference_buying_price' => $this->referencePrice($data, $product),
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
            } elseif ($data['generate_barcode'] ?? false) {
                $this->barcodes->generateInternal($product, $base);    // only on explicit request
            }

            foreach (array_values($data['extra_barcodes'] ?? []) as $i => $code) {
                if (filled($code)) {
                    $this->prefixed("extra_barcodes.{$i}", fn () => $this->barcodes->add($product, $code, BarcodeType::Manufacturer, $base, false));
                }
            }

            if (filled($data['initial_price'] ?? null)) {
                $this->prefixed('initial_price', fn () => $this->prices->setPrice($product, $base, $this->money($data['initial_price'], Decimal::MONEY, 'initial_price'), null, $actor, notes: __('Initial price')));
            }

            foreach (array_values($data['packaging'] ?? []) as $i => $pack) {
                $this->addPackaging($company, $product->fresh('baseUnit'), $pack, $actor, "packaging.{$i}");
            }

            return $product->fresh();
        });
    }

    /** Packaging / extra unit at registration: conversion, optional barcode and price. */
    private function addPackaging(Company $company, Product $product, array $pack, User $actor, string $field): void
    {
        $unit = Unit::forCompany($company)->where('is_active', true)->find($pack['unit_id'] ?? 0)
            ?? throw BusinessRuleException::make("{$field}.unit", __('Select a valid unit.'));

        $productUnit = $this->prefixed($field, fn () => $this->conversions->setUnit(
            $product, $unit, filled($pack['factor'] ?? null) ? (string) $pack['factor'] : null,
            (bool) ($pack['can_purchase'] ?? true), (bool) ($pack['can_sell'] ?? true),
        ));

        if (filled($pack['barcode'] ?? null)) {
            $this->prefixed("{$field}.barcode", fn () => $this->barcodes->add($product, $pack['barcode'], BarcodeType::Packaging, $productUnit, false));
        }

        if (filled($pack['price'] ?? null)) {
            $this->prefixed("{$field}.price", fn () => $this->prices->setPrice($product, $productUnit, $this->money($pack['price'], Decimal::MONEY, 'price'), null, $actor, notes: __('Initial price')));
        }
    }

    /**
     * Change the company-wide base-unit selling price from the product form.
     * Goes through PriceService (new effective-dated record, history kept);
     * a no-op when the price is unchanged.
     */
    public function changeBasePrice(Product $product, string $price, User $actor): void
    {
        $price = $this->money($price, Decimal::MONEY, 'initial_price');
        $base = ProductUnit::forCompany($product->company_id)->where('product_id', $product->id)->where('is_base', true)->firstOrFail();

        if (Decimal::cmp($this->prices->resolve($product, $base)->price, $price) !== 0) {
            $this->prefixed('initial_price', fn () => $this->prices->setPrice($product, $base, $price, null, $actor));
        }
    }

    /**
     * Run $callback and re-key its validation errors under $prefix
     * (e.g. "barcode" -> "packaging.0.barcode") so the form shows each
     * message next to the right field.
     */
    private function prefixed(string $prefix, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->flatten()->all();
            throw ValidationException::withMessages([$prefix => $messages]);
        }
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
                'reference_buying_price' => $this->referencePrice($data, $product),
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

    private function referencePrice(array $data, Product $product): ?string
    {
        if (! array_key_exists('reference_buying_price', $data)) {
            return $product->reference_buying_price;
        }

        return filled($data['reference_buying_price']) ? $this->money($data['reference_buying_price'], Decimal::COST, 'reference_buying_price') : null;
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
