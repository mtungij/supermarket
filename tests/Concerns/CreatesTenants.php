<?php

namespace Tests\Concerns;

use App\Enums\SystemRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Catalogue\CompanyDefaultsProvisioner;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantResolver;

trait CreatesTenants
{
    /**
     * A company with a head-office branch and an active subscription.
     *
     * @param  array<string, mixed>  $plan  Plan attribute overrides (limits).
     */
    protected function createCompany(array $plan = [], array $attributes = []): Company
    {
        $company = Company::factory()->create($attributes);

        Branch::factory()->create([
            'company_id' => $company->id,
            'name' => $company->name.' HQ',
            'code' => 'BR001',
            'is_head_office' => true,
        ]);

        Subscription::factory()->create([
            'company_id' => $company->id,
            'subscription_plan_id' => SubscriptionPlan::factory()->create($plan)->id,
        ]);

        // Phase 2 defaults: units, categories, stock locations.
        app(CompanyDefaultsProvisioner::class)->provision($company);

        return $company;
    }

    protected function headOffice(Company $company): Branch
    {
        return Branch::withoutTenancy()->where('company_id', $company->id)->where('is_head_office', true)->firstOrFail();
    }

    protected function createBranch(Company $company, array $attributes = []): Branch
    {
        $branch = Branch::factory()->create(['company_id' => $company->id] + $attributes);
        app(CompanyDefaultsProvisioner::class)->provisionBranch($branch);

        return $branch;
    }

    /**
     * @param  list<Branch>  $branches  Branch assignments for branch-scoped roles.
     */
    protected function addMember(Company $company, SystemRole $role, array $branches = [], ?User $user = null, bool $active = true): User
    {
        $user ??= User::factory()->create();

        $factory = CompanyUser::factory()->role($role);
        if (! $active) {
            $factory = $factory->inactive();
        }

        $membership = $factory->create(['company_id' => $company->id, 'user_id' => $user->id]);

        foreach ($branches as $branch) {
            $membership->branches()->attach($branch->id, ['company_id' => $company->id]);
        }

        if ($role === SystemRole::CompanyOwner) {
            $company->forceFill(['owner_user_id' => $user->id])->save();
        }

        return $user;
    }

    protected function createOwner(Company $company): User
    {
        return $this->addMember($company, SystemRole::CompanyOwner);
    }

    /**
     * Authenticate and resolve the tenant context in-process (for Livewire
     * component tests and direct service calls).
     */
    protected function actingAsTenant(User $user, ?Company $company = null): TenantContext
    {
        $this->actingAs($user);

        if ($company !== null) {
            session()->put(TenantResolver::SESSION_COMPANY, $company->id);
        }

        app(TenantResolver::class)->resolve($user, app('session.store'));

        return app(TenantContext::class);
    }
}
