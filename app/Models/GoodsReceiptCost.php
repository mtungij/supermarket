<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** An additional (landed) cost on a goods receipt, excl. VAT. */
class GoodsReceiptCost extends Model
{
    use BelongsToCompany;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'goods_receipt_id' => 'integer', 'amount' => 'decimal:2'];
    }

    public function label(): string
    {
        return config('inventory.landed_cost_types.'.$this->cost_type, ucfirst($this->cost_type));
    }
}
