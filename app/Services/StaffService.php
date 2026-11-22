<?php

namespace App\Services;

use App\Enums\PlanLimit;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manages company memberships. (Invitation UI arrives in Phase 2.)
 */
class StaffService
{
    public function __construct(private readonly SubscriptionLimiter $limiter) {}

    /**
     * @param  list<int>  $branchIds  Branches for branch-scoped roles.
     */
    public function addMember(Company $company, User $user, Role $role, array $branchIds = [], ?User $invitedBy = null): CompanyUser
    {
        if (! $role->isAssignableIn($company)) {
            // Rejects platform roles and other companies' custom roles.
            throw new TenantViolationException('Role is not assignable in this company.');
        }

        if ($user->isPlatformAdmin()) {
            throw ValidationException::withMessages([
                'email' => __('Platform administrator accounts cannot be added to a company.'),
            ]);
        }

        return DB::transaction(function () use ($company, $user, $role, $branchIds, $invitedBy) {
            $company = $this->limiter->lockCompany($company);

            if (CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['email' => __('This user is already a member of the company.')]);
            }

            $this->limiter->ensureCanAdd($company, PlanLimit::Staff, 'email');

            $validBranchIds = $company->branches()->whereIn('id', $branchIds)->pluck('id');

            if ($validBranchIds->count() !== count(array_unique($branchIds))) {
                throw new TenantViolationException('One or more branches do not belong to this company.');
            }

            $membership = CompanyUser::query()->forceCreate([
                'company_id' => $company->id,
                'user_id' => $user->id,
                'role_id' => $role->id,
                'is_active' => true,
                'all_branches' => $role->all_branches,
                'invited_by' => $invitedBy?->id,
                'joined_at' => now(),
            ]);

            $membership->branches()->attach(
                $validBranchIds->mapWithKeys(fn ($id) => [$id => ['company_id' => $company->id]])->all()
            );

            return $membership;
        });
    }

    public function deactivate(CompanyUser $membership): void
    {
        $membership->forceFill(['is_active' => false, 'deactivated_at' => now()])->save();
    }
}
