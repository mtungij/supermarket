<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasDocumentEvents;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Status changes go exclusively through PurchaseOrderService. */
class PurchaseOrder extends Model
{
    use BelongsToCompany, HasDocumentEvents, HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer', 'branch_id' => 'integer', 'supplier_id' => 'integer',
            'status' => PurchaseOrderStatus::class,
            'order_date' => 'date', 'expected_date' => 'date',
            'subtotal' => 'decimal:2', 'discount_total' => 'decimal:2', 'tax_total' => 'decimal:2', 'total' => 'decimal:2',
            'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime', 'closed_at' => 'datetime',
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

    public function lines(): HasMany
    {
        return $this->ownedHasMany(PurchaseOrderLine::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->ownedBelongsTo(Supplier::class);
    }

    public function branch(): BelongsTo
    {
        return $this->ownedBelongsTo(Branch::class);
    }

    public function goodsReceipts(): HasMany
    {
        return $this->ownedHasMany(GoodsReceipt::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
