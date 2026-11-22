<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantViolationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Marks a model as tenant-owned (has a company_id column).
 *
 * - Reads are filtered to the current company (fail-closed, see CompanyScope).
 * - On create, company_id is taken from the tenant context; an explicit,
 *   different company_id is rejected.
 * - company_id can never be changed after creation.
 *
 * company_id must NOT be listed in the model's fillable attributes.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function ($model): void {
            $context = app(TenantContext::class);
            $currentId = $context->isBypassed() ? null : $context->companyId();

            if ($currentId !== null) {
                if ($model->company_id !== null && (int) $model->company_id !== $currentId) {
                    throw new TenantViolationException(sprintf(
                        'Refusing to create %s for company %s from tenant context %s.',
                        $model::class, $model->company_id, $currentId,
                    ));
                }

                $model->company_id = $currentId;
            }

            if ($model->company_id === null) {
                throw new TenantViolationException(sprintf('%s requires a company_id.', $model::class));
            }
        });

        static::updating(function ($model): void {
            if ($model->isDirty('company_id')) {
                throw new TenantViolationException(sprintf('%s cannot be moved between companies.', $model::class));
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Query across all companies. Platform/system code only.
     */
    public static function withoutTenancy(): Builder
    {
        return static::query()->withoutGlobalScope(CompanyScope::class);
    }

    /**
     * Explicitly company-filtered query (works with or without a tenant
     * context). Use in services that receive a Company argument.
     */
    public static function forCompany(Company|int $company): Builder
    {
        $id = $company instanceof Company ? $company->id : $company;

        return static::withoutTenancy()->where((new static)->qualifyColumn('company_id'), $id);
    }

    /**
     * Relations between records of the SAME company. Composite foreign keys
     * (x_id, company_id) guarantee both sides share a company, so the tenant
     * scope is removed to keep relations working in console/queue code.
     */
    protected function ownedHasMany(string $related, ?string $foreignKey = null): HasMany
    {
        return $this->hasMany($related, $foreignKey)->withoutGlobalScope(CompanyScope::class);
    }

    protected function ownedBelongsTo(string $related, ?string $foreignKey = null): BelongsTo
    {
        // Resolve the relation name from the CALLER (Laravel would otherwise
        // guess it from this wrapper's name and build the wrong foreign key).
        $relation = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'];
        $foreignKey ??= Str::snake($relation).'_id';

        return $this->belongsTo($related, $foreignKey, null, $relation)->withoutGlobalScope(CompanyScope::class);
    }

    public function belongsToCompany(Company|int|null $company): bool
    {
        $id = $company instanceof Company ? $company->id : $company;

        return $id !== null && (int) $this->company_id === (int) $id;
    }
}
