<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\IsImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The lot (batch) part of a sale line; the source of its stock movement. */
class SaleItemAllocation extends Model
{
    use BelongsToCompany, IsImmutable;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer', 'sale_item_id' => 'integer', 'product_id' => 'integer', 'batch_id' => 'integer',
            'quantity_base' => 'decimal:4', 'unit_cost' => 'decimal:4', 'cost_amount' => 'decimal:4',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->ownedBelongsTo(StockBatch::class, 'batch_id');
    }
}
