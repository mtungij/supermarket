<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Livewire\Company\BranchManager;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class StaffPermissionsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    public function test_all_required_roles_exist_with_correct_scope(): void
    {
        foreach (SystemRole::cases() as $role) {
            $model = Role::system($role->value);
            $this->assertSame($role->scope(), $model->scope, $role->value);
        }

        $this->assertSame(8, Role::query()->where('is_system', true)->count());
    }

    public function test_cashier_is_limited_to_pos_and_cannot_manage_company(): void
    {
        $company = $this->createCompany();
        $cashier = $this->addMember($company, SystemRole::Cashier, [$this->headOffice($company)]);

        $this->actingAs($cashier)->get(route('branch.dashboard'))->assertOk();
        $this->actingAs($cashier)->get(route('company.dashboard'))->assertForbidden();
        $this->actingAs($cashier)->get(route('company.branches.index'))->assertForbidden();
        $this->actingAs($cashier)->get(route('company.staff.index'))->assertForbidden();
        $this->actingAs($cashier)->get(route('company.pos-devices.index'))->assertForbidden();
        $this->actingAs($cashier)->get(route('company.subscription'))->assertForbidden();

        $this->actingAsTenant($cashier, $company);
        $this->assertTrue($cashier->can(Permission::PosAccess->value));
        $this->assertTrue($cashier->can(Permission::SalesCreate->value));
        $this->assertFalse($cashier->can(Permission::SalesVoid->value));
        $this->assertFalse($cashier->can(Permission::ReportsFinancial->value));
    }

    public function test_auditor_can_view_but_not_change(): void
    {
        $company = $this->createCompany(['max_branches' => 10]);
        $auditor = $this->addMember($company, SystemRole::Auditor);

        $this->actingAs($auditor)->get(route('company.branches.index'))->assertOk()->assertDontSee('Add branch');
        $this->actingAs($auditor)->get(route('company.staff.index'))->assertOk();

        $this->actingAsTenant($auditor, $company);
        Livewire::test(BranchManager::class)
            ->set('name', 'Not allowed')
            ->call('save')
            ->assertForbidden();

        $this->assertSame(1, Branch::withoutTenancy()->where('company_id', $company->id)->count());
    }

    public function test_store_keeper_and_accountant_permissions(): void
    {
        $company = $this->createCompany();
        $storeKeeper = $this->addMember($company, SystemRole::StoreKeeper, [$this->headOffice($company)]);
        $accountant = $this->addMember($company, SystemRole::Accountant);

        $this->actingAsTenant($storeKeeper, $company);
        $this->assertTrue($storeKeeper->can('purchases.receive'));
        $this->assertFalse($storeKeeper->can('purchases.approve'));
        $this->assertFalse($storeKeeper->can('inventory.adjust.approve'));
        $this->assertFalse($storeKeeper->can('pos.access'));
        $this->assertFalse($storeKeeper->can('expenses.manage'));

        $this->actingAsTenant($accountant, $company);
        $this->assertTrue($accountant->can('expenses.manage'));
        $this->assertTrue($accountant->can('reports.financial'));
        $this->assertFalse($accountant->can('branches.manage'));
        $this->assertFalse($accountant->can('sales.create'));
    }

    public function test_tenant_users_cannot_access_platform_area(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);

        $this->actingAs($owner)->get(route('platform.dashboard'))->assertForbidden();
        $this->actingAs($owner)->get(route('platform.companies.index'))->assertForbidden();
        $this->assertFalse($owner->can('platform.access'));
    }

    public function test_platform_admin_is_separated_from_tenant_roles(): void
    {
        $company = $this->createCompany();
        $admin = User::factory()->platformAdmin()->create();

        $this->actingAs($admin)->get('/dashboard')->assertRedirect(route('platform.dashboard'));
        $this->actingAs($admin)->get(route('platform.dashboard'))->assertOk();
        $this->actingAs($admin)->get(route('platform.companies.index'))->assertOk()->assertSee($company->name);

        // No membership => no tenant access and no tenant permissions.
        $this->actingAs($admin)->get(route('company.dashboard'))->assertRedirect(route('platform.dashboard'));
        $this->assertTrue($admin->can('platform.access'));
        $this->assertFalse($admin->can('branches.view'));
    }

    public function test_platform_permissions_cannot_be_held_by_tenant_roles(): void
    {
        foreach (SystemRole::tenantRoles() as $role) {
            foreach ($role->permissions() as $permission) {
                $this->assertNotSame('platform', $permission->group(), "{$role->value} holds {$permission->value}");
            }
        }
    }

    public function test_inactive_user_has_no_permissions(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);

        $owner->forceFill(['is_active' => false]);
        $this->assertFalse($owner->hasPermission('company.view'));
    }
}
