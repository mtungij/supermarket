<?php

namespace App\Models;

use App\Enums\UnitDimension;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'code', 'is_active'])]
class Unit extends Model
{
    use BelongsToCompany, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'dimension' => UnitDimension::class,
            'ratio' => 'decimal:9',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
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
}
