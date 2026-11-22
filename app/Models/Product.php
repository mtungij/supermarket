<?php

namespace App\Models;

use App\Enums\ProductType;
use App\Enums\TaxClass;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Company-wide catalogue item. One record per company, shared by all
 * branches; branch differences live in prices, balances and costs.
 *
 * category_id, brand_id, base_unit_id, product_type, image_path and the
 * cost fields are set by App\Services\Catalogue\ProductService only.
 */
#[Fillable([
    'name', 'sku', 'description', 'tax_class',
    'min_stock_level', 'reorder_level', 'track_expiry', 'track_batches', 'is_active',
])]
class Product extends Model
{
    use BelongsToCompany, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'category_id' => 'integer',
            'brand_id' => 'integer',
            'base_unit_id' => 'integer',
            'product_type' => ProductType::class,
            'tax_class' => TaxClass::class,
            'purchase_cost' => 'decimal:4',
            'selling_price' => 'decimal:2',
            'min_stock_level' => 'decimal:4',
            'reorder_level' => 'decimal:4',
            'track_expiry' => 'boolean',
            'track_batches' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function category(): BelongsTo
    {
        return $this->ownedBelongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->ownedBelongsTo(Brand::class);
    }

    public function baseUnit(): BelongsTo
    {
        return $this->ownedBelongsTo(Unit::class, 'base_unit_id');
    }

    public function productUnits(): HasMany
    {
        return $this->ownedHasMany(ProductUnit::class);
    }

    public function baseProductUnit(): HasOne
    {
        return $this->hasOne(ProductUnit::class)->withoutGlobalScopes()->where('is_base', true);
    }

    public function barcodes(): HasMany
    {
        return $this->ownedHasMany(ProductBarcode::class);
    }

    public function prices(): HasMany
    {
        return $this->ownedHasMany(ProductPrice::class);
    }

    public function batches(): HasMany
    {
        return $this->ownedHasMany(StockBatch::class);
    }

    public function requiresBatch(): bool
    {
        return $this->track_batches || $this->track_expiry;
    }
}
