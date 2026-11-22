<?php

namespace App\Models;

use App\Enums\RoleScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Persisted mirror of App\Enums\Permission (synced by the seeder).
 */
class Permission extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['scope' => RoleScope::class];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
