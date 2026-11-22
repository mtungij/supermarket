<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Branch;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

class BranchPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::BranchesView);
    }

    public function view(User $user, Branch $branch): bool
    {
        return $user->hasPermission(Permission::BranchesView) && $this->accessible($branch);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::BranchesManage);
    }

    public function update(User $user, Branch $branch): bool
    {
        return $user->hasPermission(Permission::BranchesManage) && $this->accessible($branch);
    }

    /** Same company AND the membership may access this branch. */
    private function accessible(Branch $branch): bool
    {
        $membership = $this->context->membership();

        return $membership !== null
            && $branch->belongsToCompany($this->context->companyId())
            && $membership->canAccessBranch($branch);
    }
}
