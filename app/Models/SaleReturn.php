<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\IsImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Immutable customer return (refund) against an original sale. */
class SaleReturn extends Model
{
    use BelongsToCompany, HasUuids, IsImmutable;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer', 'branch_id' => 'integer', 'sale_id' => 'integer', 'processed_by' => 'integer', 'refund_method_id' => 'integer',
            'refund_total' => 'decimal:2', 'tax_total' => 'decimal:2', 'net_total' => 'decimal:2', 'cost_total' => 'decimal:4',
            'returned_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function sale(): BelongsTo
    {
        return $this->ownedBelongsTo(Sale::class);
    }

    public function items(): HasMany
    {
        return $this->ownedHasMany(SaleReturnItem::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /** The replacement sale of an exchange, if any. */
    public function exchangeSale(): HasOne
    {
        return $this->hasOne(Sale::class, 'exchange_return_id')->withoutGlobalScopes();
    }
}
