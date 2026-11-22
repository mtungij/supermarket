<?php

namespace App\Models;

use App\Enums\StockMovementType;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\IsImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Immutable stock ledger entry. */
class StockMovement extends Model
{
    use BelongsToCompany, HasUuids, IsImmutable;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer', 'branch_id' => 'integer', 'location_id' => 'integer',
            'product_id' => 'integer', 'batch_id' => 'integer', 'product_unit_id' => 'integer',
            'type' => StockMovementType::class,
            'quantity' => 'decimal:4', 'unit_cost' => 'decimal:4', 'total_cost' => 'decimal:4',
            'balance_after' => 'decimal:4', 'branch_quantity_after' => 'decimal:4',
            'average_cost_after' => 'decimal:4', 'entered_quantity' => 'decimal:4',
            'conversion_factor' => 'decimal:6', 'occurred_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function product(): BelongsTo
    {
        return $this->ownedBelongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->ownedBelongsTo(StockLocation::class, 'location_id');
    }

    public function batch(): BelongsTo
    {
        return $this->ownedBelongsTo(StockBatch::class, 'batch_id');
    }

    public function branch(): BelongsTo
    {
        return $this->ownedBelongsTo(Branch::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
