<?php

namespace App\Models;

use App\Enums\LocationType;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** branch_id and type are set by App\Services\Inventory\StockLocationService. */
#[Fillable(['name', 'code', 'is_active'])]
class StockLocation extends Model
{
    use BelongsToCompany, HasUuids;

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'branch_id' => 'integer', 'type' => LocationType::class, 'is_active' => 'boolean', 'is_sellable' => 'boolean'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function branch(): BelongsTo
    {
        return $this->ownedBelongsTo(Branch::class);
    }
}
