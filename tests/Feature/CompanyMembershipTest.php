<?php

namespace Tests\Feature;

use App\Enums\CompanyStatus;
use App\Enums\SystemRole;
use App\Models\CompanyUser;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class CompanyMembershipTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    public function test_company_owner_is_recorded_on_company_and_membership(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);

        $this->assertSame($owner->id, $company->fresh()->owner_user_id);
        $membership = $owner->memberships()->sole();
        $this->assertTrue($membership->isOwner());
        $this->assertTrue($membership->all_branches);
    }

    public function test_user_can_belong_to_multiple_companies_with_different_roles(): void
    {
        $a = $this->createCompany();
        $b = $this->createCompany();
        $user = $this->createOwner($a);
        $this->addMember($b, SystemRole::Accountant, user: $user);

        $resolver = app(TenantResolver::class);
        $this->assertCount(2, $resolver->activeMemberships($user));

        $context = $this->actingAsTenant($user, $a);
        $this->assertSame($a->id, $context->companyId());
        $this->assertTrue($user->hasPermission('branches.manage'));

        $context = $this->actingAsTenant($user, $b);
        $this->assertSame($b->id, $context->companyId());
        $this->assertFalse($user->hasPermission('branches.manage'));
        $this->assertTrue($user->hasPermission('reports.financial'));
    }

    public function test_multi_company_user_can_switch_companies(): void
    {
        $a = $this->createCompany();
        $b = $this->createCompany();
        $user = $this->createOwner($a);
        $this->createOwner($b);
        $this->addMember($b, SystemRole::Auditor, user: $user);

        $this->actingAs($user)->get(route('company.dashboard'))->assertSee($a->name);

        $this->actingAs($user)
            ->post(route('tenant.company.switch'), ['company' => $b->uuid])
            ->assertRedirect(route('dashboard'));

        $this->assertSame($b->id, session(TenantResolver::SESSION_COMPANY));
        $this->actingAs($user)->get(route('company.dashboard'))->assertSee($b->name)->assertDontSee($a->name.' HQ');
    }

    public function test_user_cannot_switch_to_a_company_they_do_not_belong_to(): void
    {
        $a = $this->createCompany();
        $other = $this->createCompany();
        $user = $this->createOwner($a);

        $this->actingAs($user)
            ->post(route('tenant.company.switch'), ['company' => $other->uuid])
            ->assertForbidden();

        $this->assertNotSame($other->id, session(TenantResolver::SESSION_COMPANY));
    }

    public function test_user_cannot_switch_into_an_inactive_membership(): void
    {
        $a = $this->createCompany();
        $b = $this->createCompany();
        $user = $this->createOwner($a);
        $this->addMember($b, SystemRole::Cashier, user: $user, active: false);

        $this->actingAs($user)
            ->post(route('tenant.company.switch'), ['company' => $b->uuid])
            ->assertForbidden();
    }

    public function test_user_cannot_switch_into_a_suspended_company(): void
    {
        $a = $this->createCompany();
        $b = $this->createCompany();
        $user = $this->createOwner($a);
        $this->addMember($b, SystemRole::Auditor, user: $user);
        $b->forceFill(['status' => CompanyStatus::Suspended])->save();

        $this->actingAs($user)
            ->post(route('tenant.company.switch'), ['company' => $b->uuid])
            ->assertForbidden();
    }

    public function test_deactivated_membership_loses_access_immediately(): void
    {
        $company = $this->createCompany();
        $user = $this->addMember($company, SystemRole::BranchManager, [$this->headOffice($company)]);

        $this->actingAs($user)->get(route('branch.dashboard'))->assertOk();

        CompanyUser::query()->where('user_id', $user->id)->update(['is_active' => false]);

        $this->actingAs($user)->get(route('branch.dashboard'))->assertRedirect(route('no-company'));
    }

    public function test_suspended_company_members_lose_access(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $company->forceFill(['status' => CompanyStatus::Suspended])->save();

        $this->actingAs($owner)->get(route('company.dashboard'))->assertRedirect(route('no-company'));
    }

    public function test_same_user_cannot_join_a_company_twice(): void
    {
        $company = $this->createCompany();
        $user = $this->createOwner($company);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->addMember($company, SystemRole::Cashier, user: $user);
    }
}
