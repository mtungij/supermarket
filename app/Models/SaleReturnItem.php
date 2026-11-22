<?php

namespace App\Models;

use App\Enums\ReturnDisposition;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\IsImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleReturnItem extends Model
{
    use BelongsToCompany, IsImmutable;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer', 'sale_return_id' => 'integer', 'sale_item_id' => 'integer', 'sale_item_allocation_id' => 'integer',
            'product_id' => 'integer', 'batch_id' => 'integer', 'location_id' => 'integer',
            'disposition' => ReturnDisposition::class, 'quantity_base' => 'decimal:4', 'refund_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2', 'unit_cost' => 'decimal:4', 'cost_amount' => 'decimal:4',
        ];
    }

    public function saleItem(): BelongsTo
    {
        return $this->ownedBelongsTo(SaleItem::class);
    }

    public function location(): BelongsTo
    {
        return $this->ownedBelongsTo(StockLocation::class, 'location_id');
    }
}
