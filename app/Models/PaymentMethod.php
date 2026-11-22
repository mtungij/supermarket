<?php

namespace App\Models;

use App\Enums\PaymentMethodType;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use BelongsToCompany, HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'type' => PaymentMethodType::class, 'requires_reference' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }
}
