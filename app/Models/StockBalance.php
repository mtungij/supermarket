<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Derived current quantity. Written exclusively by StockLedger inside the
 * same transaction as the corresponding StockMovement. Not editable by
 * forms: every attribute is guarded.
 */
class StockBalance extends Model
{
    use BelongsToCompany;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer', 'branch_id' => 'integer', 'location_id' => 'integer',
            'product_id' => 'integer', 'batch_id' => 'integer', 'quantity' => 'decimal:4',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->ownedBelongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->ownedBelongsTo(StockLocation::class, 'location_id');
    }

    public function batch(): BelongsTo
    {
        return $this->ownedBelongsTo(StockBatch::class, 'batch_id');
    }
}
