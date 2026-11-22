<?php

namespace App\Models;

use App\Enums\CompanyStatus;
use App\Enums\SubscriptionStatus;
use App\Support\Tenancy\CompanyScope;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A tenant: one supermarket business. The root of all tenant data.
 */
#[Fillable(['name', 'email', 'phone', 'address', 'country_code', 'currency', 'timezone', 'locale', 'settings'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
            'settings' => 'array',
            'owner_user_id' => 'integer',
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

    public function isActive(): bool
    {
        return $this->status === CompanyStatus::Active;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(CompanyUser::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(CompanyUser::class)
            ->withPivot(['id', 'role_id', 'is_active', 'all_branches'])
            ->withTimestamps();
    }

    /**
     * Branches of this company. The relation constrains by company_id, so
     * the tenant global scope is removed to make it usable outside a
     * tenant context (e.g. platform admin, onboarding).
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class)->withoutGlobalScopes([CompanyScope::class]);
    }

    public function posDevices(): HasMany
    {
        return $this->hasMany(PosDevice::class)->withoutGlobalScopes([CompanyScope::class]);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class)->withoutGlobalScopes([CompanyScope::class]);
    }

    /**
     * The subscription currently in force (trialing/active and not ended).
     */
    public function currentSubscription(): ?Subscription
    {
        return $this->subscriptions()
            ->with('plan')
            ->whereIn('status', SubscriptionStatus::usableValues())
            ->where('starts_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->latest('starts_at')
            ->latest('id')
            ->first();
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }
}
