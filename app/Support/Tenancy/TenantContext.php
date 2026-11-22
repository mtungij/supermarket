<?php

namespace App\Support\Tenancy;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;

/**
 * The tenant (company) and branch the current request is operating in.
 *
 * Populated ONLY by {@see TenantResolver} from the authenticated user's
 * verified, active memberships, never directly from request input.
 * Bound as a scoped singleton, so it is reset between requests/jobs.
 */
class TenantContext
{
    private ?Company $company = null;

    private ?CompanyUser $membership = null;

    private ?Branch $branch = null;

    private int $bypassDepth = 0;

    /** @var array<string, true>|null */
    private ?array $permissionCache = null;

    public function set(Company $company, CompanyUser $membership, ?Branch $branch = null): void
    {
        if ($membership->company_id !== $company->id) {
            throw new TenantViolationException('Membership does not belong to the given company.');
        }

        if ($branch !== null && $branch->company_id !== $company->id) {
            throw new TenantViolationException('Branch does not belong to the given company.');
        }

        $this->company = $company;
        $this->membership = $membership;
        $this->branch = $branch;
        $this->permissionCache = null;
    }

    public function setBranch(?Branch $branch): void
    {
        if ($branch !== null && $branch->company_id !== $this->companyId()) {
            throw new TenantViolationException('Branch does not belong to the current company.');
        }

        $this->branch = $branch;
    }

    public function clear(): void
    {
        $this->company = null;
        $this->membership = null;
        $this->branch = null;
        $this->permissionCache = null;
    }

    public function hasCompany(): bool
    {
        return $this->company !== null;
    }

    public function company(): ?Company
    {
        return $this->company;
    }

    public function companyId(): ?int
    {
        return $this->company?->id;
    }

    public function membership(): ?CompanyUser
    {
        return $this->membership;
    }

    public function branch(): ?Branch
    {
        return $this->branch;
    }

    public function branchId(): ?int
    {
        return $this->branch?->id;
    }

    /**
     * Tenant permission slugs granted by the current membership's role.
     *
     * @return array<string, true>
     */
    public function permissions(): array
    {
        if ($this->permissionCache !== null) {
            return $this->permissionCache;
        }

        $role = $this->membership?->role;

        return $this->permissionCache = $role === null
            ? []
            : array_fill_keys($role->permissions->pluck('slug')->all(), true);
    }

    /**
     * Run a callback with tenant scoping disabled. Use only for
     * platform-level or system code that must legitimately see every
     * company's data (platform admin, onboarding, resolver internals).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withoutTenancy(callable $callback): mixed
    {
        $this->bypassDepth++;

        try {
            return $callback();
        } finally {
            $this->bypassDepth--;
        }
    }

    public function isBypassed(): bool
    {
        return $this->bypassDepth > 0;
    }
}
