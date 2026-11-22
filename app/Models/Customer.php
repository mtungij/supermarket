<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Company-scoped registered customer. Walk-in sales have no customer. */
#[Fillable(['name', 'phone', 'email', 'notes', 'is_active'])]
class Customer extends Model
{
    use BelongsToCompany, HasUuids;

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'is_active' => 'boolean'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function sales(): HasMany
    {
        return $this->ownedHasMany(Sale::class);
    }
}
