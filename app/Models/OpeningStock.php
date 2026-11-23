<?php

namespace App\Models;

use App\Enums\OpeningStockStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasDocumentEvents;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Opening-stock document (see App\Services\Inventory\OpeningStockService). */
class OpeningStock extends Model
{
    use BelongsToCompany, HasDocumentEvents, HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer', 'branch_id' => 'integer', 'location_id' => 'integer',
            'status' => OpeningStockStatus::class, 'opening_date' => 'date',
            'line_count' => 'integer', 'total_quantity_base' => 'decimal:4', 'total_value' => 'decimal:4',
            'posted_at' => 'datetime', 'cancelled_at' => 'datetime',
            'created_by' => 'integer', 'posted_by' => 'integer',
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

    public function isDraft(): bool
    {
        return $this->status === OpeningStockStatus::Draft;
    }

    public function lines(): HasMany
    {
        return $this->ownedHasMany(OpeningStockLine::class);
    }

    public function branch(): BelongsTo
    {
        return $this->ownedBelongsTo(Branch::class);
    }

    public function location(): BelongsTo
    {
        return $this->ownedBelongsTo(StockLocation::class, 'location_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function zeroCostApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'zero_cost_approved_by');
    }
}
