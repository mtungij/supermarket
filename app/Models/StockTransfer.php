<?php

namespace App\Models;

use App\Enums\StockTransferStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasDocumentEvents;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransfer extends Model
{
    use BelongsToCompany, HasDocumentEvents, HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer', 'source_branch_id' => 'integer', 'source_location_id' => 'integer',
            'destination_branch_id' => 'integer', 'destination_location_id' => 'integer',
            'status' => StockTransferStatus::class,
            'dispatched_at' => 'datetime', 'received_at' => 'datetime', 'cancelled_at' => 'datetime',
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

    public function isInterBranch(): bool
    {
        return $this->source_branch_id !== $this->destination_branch_id;
    }

    public function lines(): HasMany
    {
        return $this->ownedHasMany(StockTransferLine::class);
    }

    public function sourceBranch(): BelongsTo
    {
        return $this->ownedBelongsTo(Branch::class, 'source_branch_id');
    }

    public function destinationBranch(): BelongsTo
    {
        return $this->ownedBelongsTo(Branch::class, 'destination_branch_id');
    }

    public function sourceLocation(): BelongsTo
    {
        return $this->ownedBelongsTo(StockLocation::class, 'source_location_id');
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->ownedBelongsTo(StockLocation::class, 'destination_location_id');
    }
}
