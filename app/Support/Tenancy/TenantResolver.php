<?php

namespace App\Support\Tenancy;

use App\Enums\CompanyStatus;
use App\Models\Branch;
use App\Models\CompanyUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Collection;

/**
 * Resolves the tenant context for an authenticated user.
 *
 * The session only stores the user's *preferred* company/branch id. Every
 * request re-validates that preference against the user's ACTIVE
 * memberships in ACTIVE companies, so a tampered, stale or revoked value
 * can never grant access.
 */
class TenantResolver
{
    public const SESSION_COMPANY = 'tenant.company_id';

    public const SESSION_BRANCH = 'tenant.branch_id';

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Active memberships of the user in active companies.
     *
     * @return Collection<int, CompanyUser>
     */
    public function activeMemberships(User $user): Collection
    {
        if (! $user->is_active) {
            return new Collection;
        }

        return $user->memberships()
            ->where('company_user.is_active', true)
            ->whereHas('company', fn ($q) => $q->where('status', CompanyStatus::Active->value))
            ->with(['company', 'role.permissions'])
            ->orderBy('joined_at')
            ->orderBy('id')
            ->get();
    }

    public function resolve(User $user, Session $session): ?CompanyUser
    {
        $memberships = $this->activeMemberships($user);

        if ($memberships->isEmpty()) {
            $this->context->clear();
            $session->forget([self::SESSION_COMPANY, self::SESSION_BRANCH]);

            return null;
        }

        $preferred = $session->get(self::SESSION_COMPANY);
        $membership = $memberships->first(fn (CompanyUser $m) => $m->company_id === (int) $preferred)
            ?? $memberships->first();

        if ($membership->company_id !== (int) $preferred) {
            $session->forget(self::SESSION_BRANCH);
        }

        $session->put(self::SESSION_COMPANY, $membership->company_id);

        $branch = $this->resolveBranch($membership, $session->get(self::SESSION_BRANCH));
        $session->put(self::SESSION_BRANCH, $branch?->id);

        $this->context->set($membership->company, $membership, $branch);

        return $membership;
    }

    /**
     * Switch to another company. The target is identified by its public
     * UUID and must be one of the user's active memberships.
     *
     * @throws AuthorizationException
     */
    public function switchCompany(User $user, string $companyUuid, Session $session): CompanyUser
    {
        $membership = $this->activeMemberships($user)
            ->first(fn (CompanyUser $m) => hash_equals($m->company->uuid, $companyUuid));

        if ($membership === null) {
            throw new AuthorizationException(__('You are not a member of that company.'));
        }

        $session->put(self::SESSION_COMPANY, $membership->company_id);
        $session->forget(self::SESSION_BRANCH);
        $session->regenerateToken();

        $branch = $this->resolveBranch($membership, null);
        $session->put(self::SESSION_BRANCH, $branch?->id);
        $this->context->set($membership->company, $membership, $branch);

        return $membership;
    }

    /**
     * Switch the working branch within the current company.
     *
     * @throws AuthorizationException
     */
    public function switchBranch(CompanyUser $membership, string $branchUuid, Session $session): Branch
    {
        $branch = $this->accessibleBranches($membership)
            ->first(fn (Branch $b) => hash_equals($b->uuid, $branchUuid));

        if ($branch === null) {
            throw new AuthorizationException(__('You do not have access to that branch.'));
        }

        $session->put(self::SESSION_BRANCH, $branch->id);
        $this->context->setBranch($branch);

        return $branch;
    }

    /**
     * Active branches the membership may work in.
     *
     * @return Collection<int, Branch>
     */
    public function accessibleBranches(CompanyUser $membership): Collection
    {
        if (! $membership->is_active) {
            return new Collection;
        }

        $query = Branch::withoutTenancy()
            ->where('company_id', $membership->company_id)
            ->where('is_active', true)
            ->orderByDesc('is_head_office')
            ->orderBy('name');

        if (! $membership->all_branches) {
            $query->whereIn('id', $membership->branches()->select('branches.id'));
        }

        return $query->get();
    }

    private function resolveBranch(CompanyUser $membership, mixed $preferredBranchId): ?Branch
    {
        $branches = $this->accessibleBranches($membership);

        return $branches->first(fn (Branch $b) => $b->id === (int) $preferredBranchId)
            ?? $branches->first();
    }
}
