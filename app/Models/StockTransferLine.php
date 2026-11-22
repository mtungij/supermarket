<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferLine extends Model
{
    use BelongsToCompany;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer', 'stock_transfer_id' => 'integer', 'product_id' => 'integer',
            'product_unit_id' => 'integer', 'batch_id' => 'integer',
            'conversion_factor' => 'decimal:6', 'quantity' => 'decimal:4', 'quantity_base' => 'decimal:4',
            'unit_cost_base' => 'decimal:4',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->ownedBelongsTo(Product::class);
    }

    public function productUnit(): BelongsTo
    {
        return $this->ownedBelongsTo(ProductUnit::class);
    }

    public function batch(): BelongsTo
    {
        return $this->ownedBelongsTo(StockBatch::class, 'batch_id');
    }
}
