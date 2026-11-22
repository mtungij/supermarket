<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\RoleScope;
use App\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A person who can sign in. Users are global (not tenant-owned); their
 * access to company data comes exclusively through CompanyUser memberships.
 *
 * Security-sensitive attributes (platform_role_id, is_active) are
 * intentionally NOT fillable.
 */
#[Fillable(['name', 'email', 'password', 'phone', 'locale'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'platform_role_id' => 'integer',
        ];
    }

    public function platformRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'platform_role_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(CompanyUser::class);
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)
            ->using(CompanyUser::class)
            ->withPivot(['id', 'role_id', 'is_active', 'all_branches'])
            ->withTimestamps();
    }

    public function isPlatformAdmin(): bool
    {
        if ($this->platform_role_id === null) {
            return false;
        }

        $role = $this->platformRole;

        return $role !== null
            && $role->scope === RoleScope::Platform
            && $role->slug === SystemRole::PlatformSuperAdmin->value;
    }

    /**
     * Server-side permission check.
     *
     * Platform permissions come from the user's platform role. Tenant
     * permissions come from the membership in the CURRENT tenant context;
     * a platform admin has no tenant permissions unless they are also a
     * member of that company.
     */
    public function hasPermission(Permission|string $permission): bool
    {
        $permission = $permission instanceof Permission ? $permission : Permission::tryFrom($permission);

        if ($permission === null || ! $this->is_active) {
            return false;
        }

        if ($permission->scope() === RoleScope::Platform) {
            return $this->platformRole?->scope === RoleScope::Platform
                && $this->platformRole->permissions->contains('slug', $permission->value);
        }

        $context = app(TenantContext::class);
        $membership = $context->membership();

        if ($membership === null || $membership->user_id !== $this->id || ! $membership->is_active) {
            return false;
        }

        return isset($context->permissions()[$permission->value]);
    }

    /**
     * Only send verification emails when the feature is enabled.
     */
    public function sendEmailVerificationNotification(): void
    {
        if (config('saas.email_verification')) {
            parent::sendEmailVerificationNotification();
        }
    }
}
