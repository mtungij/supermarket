<?php

namespace App\Models;

use App\Enums\GoodsReceiptStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasDocumentEvents;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsReceipt extends Model
{
    use BelongsToCompany, HasDocumentEvents, HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer', 'branch_id' => 'integer', 'location_id' => 'integer',
            'purchase_order_id' => 'integer', 'supplier_id' => 'integer',
            'status' => GoodsReceiptStatus::class, 'received_date' => 'date',
            'total' => 'decimal:2', 'goods_value' => 'decimal:2', 'landed_cost_total' => 'decimal:2', 'posted_at' => 'datetime',
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
        return $this->ownedHasMany(GoodsReceiptLine::class);
    }

    public function costs(): HasMany
    {
        return $this->ownedHasMany(GoodsReceiptCost::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->ownedBelongsTo(PurchaseOrder::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->ownedBelongsTo(Supplier::class);
    }

    public function branch(): BelongsTo
    {
        return $this->ownedBelongsTo(Branch::class);
    }

    public function location(): BelongsTo
    {
        return $this->ownedBelongsTo(StockLocation::class, 'location_id');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
