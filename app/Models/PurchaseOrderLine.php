<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderLine extends Model
{
    use BelongsToCompany;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer', 'purchase_order_id' => 'integer', 'product_id' => 'integer', 'product_unit_id' => 'integer',
            'conversion_factor' => 'decimal:6', 'quantity' => 'decimal:4', 'quantity_base' => 'decimal:4',
            'unit_cost' => 'decimal:4', 'discount_amount' => 'decimal:2', 'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2', 'line_subtotal' => 'decimal:2', 'line_total' => 'decimal:2',
            'received_quantity_base' => 'decimal:4',
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

    public function purchaseOrder(): BelongsTo
    {
        return $this->ownedBelongsTo(PurchaseOrder::class);
    }

    public function remainingBase(): string
    {
        return Decimal::max(Decimal::round(Decimal::sub($this->quantity_base, $this->received_quantity_base), Decimal::QTY), '0.0000');
    }

    /** Net cost per base unit after discount, excluding tax. */
    public function netUnitCostBase(): string
    {
        $net = Decimal::sub($this->line_subtotal, $this->discount_amount);

        return Decimal::round(Decimal::div($net, $this->quantity_base), Decimal::COST);
    }
}
