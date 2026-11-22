<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\IsImmutable;
use Illuminate\Database\Eloquent\Model;

class SupplierTransaction extends Model
{
    use BelongsToCompany, IsImmutable;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'supplier_id' => 'integer', 'amount' => 'decimal:2', 'occurred_at' => 'datetime'];
    }
}
