<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\IsImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only, effective-dated price record. Never updated: a new price is
 * a new row, so the full price history is preserved.
 */
class ProductPrice extends Model
{
    use BelongsToCompany, IsImmutable;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'product_id' => 'integer',
            'product_unit_id' => 'integer',
            'branch_id' => 'integer',
            'price' => 'decimal:2',
            'effective_from' => 'datetime',
        ];
    }

    public function productUnit(): BelongsTo
    {
        return $this->ownedBelongsTo(ProductUnit::class);
    }

    public function branch(): BelongsTo
    {
        return $this->ownedBelongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
