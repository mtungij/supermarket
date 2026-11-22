<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Branch;
use App\Models\PosDevice;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

class PosDevicePolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::PosDevicesView);
    }

    public function create(User $user, ?Branch $branch = null): bool
    {
        if (! $user->hasPermission(Permission::PosDevicesManage)) {
            return false;
        }

        return $branch === null || $this->canUseBranch($branch);
    }

    public function update(User $user, PosDevice $device): bool
    {
        return $user->hasPermission(Permission::PosDevicesManage)
            && $device->belongsToCompany($this->context->companyId())
            && $this->canUseBranch($device->branch_id);
    }

    private function canUseBranch(Branch|int $branch): bool
    {
        $membership = $this->context->membership();
        $companyId = $branch instanceof Branch ? $branch->company_id : null;

        return $membership !== null
            && ($companyId === null || $companyId === $this->context->companyId())
            && $membership->canAccessBranch($branch);
    }
}
