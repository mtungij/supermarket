<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\IsImmutable;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A completed POS sale. IMMUTABLE: never edited or deleted; returns are
 * separate SaleReturn documents. Attributed to company, branch, POS
 * device, cashier and timestamp (no shift).
 */
class Sale extends Model
{
    use BelongsToCompany, HasUuids, IsImmutable;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer', 'branch_id' => 'integer', 'pos_device_id' => 'integer', 'location_id' => 'integer',
            'customer_id' => 'integer', 'cashier_id' => 'integer', 'exchange_return_id' => 'integer',
            'prices_include_tax' => 'boolean',
            'subtotal' => 'decimal:2', 'discount_total' => 'decimal:2', 'tax_total' => 'decimal:2', 'total' => 'decimal:2',
            'net_total' => 'decimal:2', 'paid_total' => 'decimal:2', 'tendered_total' => 'decimal:2', 'change_total' => 'decimal:2',
            'cost_total' => 'decimal:4', 'item_count' => 'decimal:4', 'sold_at' => 'datetime',
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

    public function items(): HasMany
    {
        return $this->ownedHasMany(SaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->ownedHasMany(SalePayment::class);
    }

    public function returns(): HasMany
    {
        return $this->ownedHasMany(SaleReturn::class);
    }

    public function branch(): BelongsTo
    {
        return $this->ownedBelongsTo(Branch::class);
    }

    public function device(): BelongsTo
    {
        return $this->ownedBelongsTo(PosDevice::class, 'pos_device_id');
    }

    public function location(): BelongsTo
    {
        return $this->ownedBelongsTo(StockLocation::class, 'location_id');
    }

    public function customer(): BelongsTo
    {
        return $this->ownedBelongsTo(Customer::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    /** completed | partially_returned | returned (derived from return documents). */
    public function returnStatus(?string $returnedTotal = null): string
    {
        $returned = $returnedTotal ?? (string) ($this->returns()->sum('refund_total') ?: '0');

        return match (true) {
            ! Decimal::isPositive($returned) => 'completed',
            Decimal::cmp($returned, (string) $this->total) >= 0 => 'returned',
            default => 'partially_returned',
        };
    }
}
