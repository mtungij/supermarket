<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Weighted-average cost per branch & product. Written only by StockLedger. */
class InventoryCost extends Model
{
    use BelongsToCompany;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer', 'branch_id' => 'integer', 'product_id' => 'integer',
            'quantity' => 'decimal:4', 'average_cost' => 'decimal:4', 'last_cost' => 'decimal:4',
        ];
    }
}
