<?php

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use App\Enums\SystemRole;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Idempotently syncs the permission catalogue and built-in roles from
 * App\Enums\Permission and App\Enums\SystemRole. Safe to re-run on deploy.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissionIds = [];

        foreach (PermissionEnum::cases() as $case) {
            $permission = Permission::query()->firstOrNew(['slug' => $case->value]);
            $permission->forceFill([
                'name' => $case->label(),
                'group' => $case->group(),
                'scope' => $case->scope(),
            ])->save();

            $permissionIds[$case->value] = $permission->id;
        }

        // Remove permissions that no longer exist in the catalogue (e.g.
        // Phase 1 placeholders replaced by finer-grained permissions).
        Permission::query()->whereNotIn('slug', array_keys($permissionIds))->delete();

        foreach (SystemRole::cases() as $systemRole) {
            $role = Role::query()->whereNull('company_id')->where('slug', $systemRole->value)->first() ?? new Role;
            $role->forceFill([
                'company_id' => null,
                'slug' => $systemRole->value,
                'name' => $systemRole->label(),
                'scope' => $systemRole->scope(),
                'is_system' => true,
                'all_branches' => $systemRole->allBranches(),
            ])->save();

            $role->permissions()->sync(array_map(
                fn (PermissionEnum $p) => $permissionIds[$p->value],
                $systemRole->permissions(),
            ));
        }
    }
}
