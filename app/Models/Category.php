<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'is_active'])]
class Category extends Model
{
    use BelongsToCompany, HasFactory, HasUuids;

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'parent_id' => 'integer', 'is_active' => 'boolean'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function parent(): BelongsTo
    {
        return $this->ownedBelongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->ownedHasMany(Category::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->ownedHasMany(Product::class);
    }
}
