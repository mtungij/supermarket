<?php

namespace App\Models;

use App\Enums\BarcodeType;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBarcode extends Model
{
    use BelongsToCompany;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'product_id' => 'integer',
            'product_unit_id' => 'integer',
            'type' => BarcodeType::class,
            'is_primary' => 'boolean',
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
}
