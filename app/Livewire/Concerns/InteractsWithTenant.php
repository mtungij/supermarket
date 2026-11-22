<?php

namespace App\Livewire\Concerns;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\Eloquent\Collection;

/**
 * Tenant helpers for module components. Every id comes from the
 * server-side TenantContext; nothing here trusts client-supplied company
 * or branch identifiers.
 */
trait InteractsWithTenant
{
    use RequiresTenant;

    protected function company(): Company
    {
        return app(TenantContext::class)->company();
    }

    protected function actor(): User
    {
        return auth()->user();
    }

    /** The working branch chosen in the branch selector. */
    protected function currentBranch(): Branch
    {
        return app(TenantContext::class)->branch() ?? abort(403, __('No branch assigned'));
    }

    /** @return Collection<int, Branch> */
    protected function accessibleBranches(): Collection
    {
        return app(TenantResolver::class)->accessibleBranches(app(TenantContext::class)->membership());
    }

    protected function canAccessBranch(int $branchId): bool
    {
        return app(TenantContext::class)->membership()->canAccessBranch($branchId);
    }

    protected function ensureBranchAccess(int $branchId): void
    {
        abort_unless($this->canAccessBranch($branchId), 403);
    }
}
