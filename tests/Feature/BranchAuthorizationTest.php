<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Models\CompanyUser;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class BranchAuthorizationTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    public function test_branch_staff_only_resolve_to_assigned_branches(): void
    {
        $company = $this->createCompany();
        $assigned = $this->createBranch($company, ['name' => 'Assigned']);
        $cashier = $this->addMember($company, SystemRole::Cashier, [$assigned]);

        $context = $this->actingAsTenant($cashier, $company);

        $this->assertSame($assigned->id, $context->branchId());
        $this->assertSame(
            [$assigned->id],
            app(TenantResolver::class)->accessibleBranches($context->membership())->pluck('id')->all(),
        );
    }

    public function test_branch_staff_cannot_switch_to_an_unassigned_branch(): void
    {
        $company = $this->createCompany();
        $assigned = $this->createBranch($company);
        $other = $this->createBranch($company);
        $cashier = $this->addMember($company, SystemRole::Cashier, [$assigned]);

        $this->actingAs($cashier)
            ->post(route('tenant.branch.switch'), ['branch' => $other->uuid])
            ->assertForbidden();

        $this->assertNotSame($other->id, session(TenantResolver::SESSION_BRANCH));
    }

    public function test_branch_staff_can_switch_between_their_assigned_branches(): void
    {
        $company = $this->createCompany();
        $one = $this->createBranch($company);
        $two = $this->createBranch($company);
        $supervisor = $this->addMember($company, SystemRole::Supervisor, [$one, $two]);

        $this->actingAs($supervisor)
            ->from(route('branch.dashboard'))
            ->post(route('tenant.branch.switch'), ['branch' => $two->uuid])
            ->assertRedirect(route('branch.dashboard'));

        $this->assertSame($two->id, session(TenantResolver::SESSION_BRANCH));
    }

    public function test_stale_branch_in_session_is_ignored(): void
    {
        $company = $this->createCompany();
        $assigned = $this->createBranch($company);
        $other = $this->createBranch($company);
        $cashier = $this->addMember($company, SystemRole::Cashier, [$assigned]);

        $this->actingAs($cashier)
            ->withSession([TenantResolver::SESSION_COMPANY => $company->id, TenantResolver::SESSION_BRANCH => $other->id])
            ->get(route('branch.dashboard'))
            ->assertOk()
            ->assertSee($assigned->name);

        $this->assertSame($assigned->id, session(TenantResolver::SESSION_BRANCH));
    }

    public function test_branch_manager_cannot_view_another_branch_of_same_company(): void
    {
        $company = $this->createCompany();
        $mine = $this->createBranch($company);
        $notMine = $this->createBranch($company);
        $manager = $this->addMember($company, SystemRole::BranchManager, [$mine]);

        $this->actingAs($manager)->get(route('company.branches.show', $mine))->assertOk();
        $this->actingAs($manager)->get(route('company.branches.show', $notMine))->assertForbidden();
    }

    public function test_owner_can_view_every_branch(): void
    {
        $company = $this->createCompany();
        $extra = $this->createBranch($company);
        $owner = $this->createOwner($company);

        $this->actingAs($owner)->get(route('company.branches.show', $this->headOffice($company)))->assertOk();
        $this->actingAs($owner)->get(route('company.branches.show', $extra))->assertOk();
    }

    public function test_database_rejects_assigning_another_companys_branch_to_a_membership(): void
    {
        $a = $this->createCompany();
        $b = $this->createCompany();
        $this->addMember($a, SystemRole::Cashier);
        $membership = CompanyUser::query()->where('company_id', $a->id)->firstOrFail();

        $this->expectException(QueryException::class);
        // Even if application code were bypassed, the composite FK
        // (branch_id, company_id) -> branches(id, company_id) refuses it.
        $membership->branches()->attach($this->headOffice($b)->id, ['company_id' => $a->id]);
    }
}
