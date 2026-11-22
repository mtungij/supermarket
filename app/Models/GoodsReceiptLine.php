<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceiptLine extends Model
{
    use BelongsToCompany;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer', 'goods_receipt_id' => 'integer', 'purchase_order_id' => 'integer',
            'purchase_order_line_id' => 'integer', 'product_id' => 'integer', 'product_unit_id' => 'integer', 'batch_id' => 'integer',
            'conversion_factor' => 'decimal:6', 'quantity' => 'decimal:4', 'quantity_base' => 'decimal:4',
            'unit_cost' => 'decimal:4', 'unit_cost_base' => 'decimal:4', 'line_total' => 'decimal:2',
            'location_id' => 'integer', 'goods_value' => 'decimal:2', 'tax_amount' => 'decimal:2',
            'landed_cost_amount' => 'decimal:2', 'landed_unit_cost_base' => 'decimal:4',
            'manufactured_on' => 'date', 'expires_on' => 'date',
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

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->ownedBelongsTo(PurchaseOrderLine::class);
    }

    public function location(): BelongsTo
    {
        return $this->ownedBelongsTo(StockLocation::class, 'location_id');
    }

    /** Inventory cost per base unit; historical lines (before landed costs) use the supplier cost. */
    public function inventoryUnitCostBase(): string
    {
        return (string) ($this->landed_unit_cost_base ?? $this->unit_cost_base);
    }

    public function batch(): BelongsTo
    {
        return $this->ownedBelongsTo(StockBatch::class, 'batch_id');
    }
}
