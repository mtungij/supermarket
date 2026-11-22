<?php

namespace App\Models;

use App\Enums\RoleScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Platform roles (scope=platform) and tenant roles (scope=tenant).
 * Tenant roles with company_id NULL are built-in templates shared by all
 * companies; company-specific roles carry their company_id.
 *
 * Roles are reference data managed by seeders/services, so every
 * attribute is guarded.
 */
class Role extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'scope' => RoleScope::class,
            'company_id' => 'integer',
            'is_system' => 'boolean',
            'all_branches' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    /** Tenant roles usable inside the given company. */
    public function scopeAssignableIn(Builder $query, Company|int $company): void
    {
        $companyId = $company instanceof Company ? $company->id : $company;

        $query->where('scope', RoleScope::Tenant->value)
            ->where(fn (Builder $q) => $q->whereNull('company_id')->orWhere('company_id', $companyId));
    }

    public function isAssignableIn(Company|int $company): bool
    {
        $companyId = $company instanceof Company ? $company->id : $company;

        return $this->scope === RoleScope::Tenant
            && ($this->company_id === null || $this->company_id === $companyId);
    }

    public static function system(string $slug): self
    {
        return static::query()->whereNull('company_id')->where('slug', $slug)->firstOrFail();
    }
}
