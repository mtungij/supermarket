<?php

namespace App\Services\Pos;

use App\Models\Sale;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may see which sales:
 *  - sales.view          -> all sales of the branches the user can access
 *                           (owners/accountants/auditors: every branch)
 *  - otherwise (cashier) -> only sales they rang up themselves
 * Always within the current company (tenant scope).
 */
class SalesQueries
{
    public function __construct(private readonly TenantContext $context, private readonly TenantResolver $resolver) {}

    public function visibleTo(User $user): Builder
    {
        $branchIds = $this->resolver->accessibleBranches($this->context->membership())->pluck('id');

        return Sale::query()->whereIn('sales.branch_id', $branchIds)
            ->when(! $user->can('sales.view'), fn ($q) => $q->where('sales.cashier_id', $user->id));
    }

    public function canSee(User $user, Sale $sale): bool
    {
        return $this->visibleTo($user)->whereKey($sale->id)->exists();
    }
}
