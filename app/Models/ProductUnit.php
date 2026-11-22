<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Product-specific unit conversion: 1 unit = factor x base unit.
 * Managed by App\Services\Catalogue\UnitConversionService.
 */
class ProductUnit extends Model
{
    use BelongsToCompany;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'product_id' => 'integer',
            'unit_id' => 'integer',
            'factor' => 'decimal:6',
            'is_base' => 'boolean',
            'can_purchase' => 'boolean',
            'can_sell' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->ownedBelongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->ownedBelongsTo(Unit::class);
    }

    public function label(): string
    {
        return $this->unit->name;
    }
}
