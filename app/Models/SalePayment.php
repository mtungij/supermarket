<?php

namespace App\Models;

use App\Enums\PaymentMethodType;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\IsImmutable;
use Illuminate\Database\Eloquent\Model;

/** Immutable payment line. Manually confirmed by staff (no provider verification). */
class SalePayment extends Model
{
    use BelongsToCompany, IsImmutable;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer', 'sale_id' => 'integer', 'payment_method_id' => 'integer',
            'method_type' => PaymentMethodType::class, 'amount' => 'decimal:2', 'tendered' => 'decimal:2',
            'change_given' => 'decimal:2', 'confirmed_manually' => 'boolean',
        ];
    }
}
