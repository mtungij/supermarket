<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\IsImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Immutable sale line with product/unit/price snapshots. */
class SaleItem extends Model
{
    use BelongsToCompany, IsImmutable;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer', 'sale_id' => 'integer', 'product_id' => 'integer', 'product_unit_id' => 'integer', 'product_price_id' => 'integer',
            'conversion_factor' => 'decimal:6', 'quantity' => 'decimal:4', 'quantity_base' => 'decimal:4',
            'unit_price' => 'decimal:2', 'discount_amount' => 'decimal:2', 'tax_rate' => 'decimal:2', 'tax_amount' => 'decimal:2',
            'net_amount' => 'decimal:2', 'line_total' => 'decimal:2', 'cost_amount' => 'decimal:4',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->ownedBelongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->ownedBelongsTo(Product::class);
    }

    public function allocations(): HasMany
    {
        return $this->ownedHasMany(SaleItemAllocation::class);
    }

    public function returnItems(): HasMany
    {
        return $this->ownedHasMany(SaleReturnItem::class);
    }
}
