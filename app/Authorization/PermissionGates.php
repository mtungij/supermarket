<?php

namespace App\Authorization;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Registers one Gate ability per permission slug, so every permission can
 * be checked server-side with $user->can('sales.create'),
 * Gate::authorize(...), the `can:` middleware or @can in Blade.
 *
 * There is intentionally no Gate::before() super-user bypass: platform
 * admins do not automatically hold tenant permissions.
 */
class PermissionGates
{
    public static function register(): void
    {
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user) => $user->hasPermission($permission));
        }
    }
}
