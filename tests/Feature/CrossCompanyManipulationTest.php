<?php

namespace Tests\Feature;

use App\Enums\PosDeviceStatus;
use App\Enums\SystemRole;
use App\Livewire\Company\PosDeviceManager;
use App\Models\PosDevice;
use App\Models\Role;
use App\Models\User;
use App\Services\PosDeviceService;
use App\Services\StaffService;
use App\Support\Tenancy\TenantResolver;
use App\Support\Tenancy\TenantViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Attempts to reach another company's data by supplying its identifiers.
 */
class CrossCompanyManipulationTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    public function test_other_companys_branch_url_returns_404(): void
    {
        $a = $this->createCompany();
        $b = $this->createCompany();
        $owner = $this->createOwner($a);

        $this->actingAs($owner)->get(route('company.branches.show', $this->headOffice($b)))->assertNotFound();
    }

    public function test_forged_company_id_in_session_is_ignored(): void
    {
        $a = $this->createCompany();
        $b = $this->createCompany();
        $owner = $this->createOwner($a);

        $this->actingAs($owner)
            ->withSession([TenantResolver::SESSION_COMPANY => $b->id, TenantResolver::SESSION_BRANCH => $this->headOffice($b)->id])
            ->get(route('company.dashboard'))
            ->assertOk()
            ->assertSee($a->name)
            ->assertDontSee($b->name);

        $this->assertSame($a->id, session(TenantResolver::SESSION_COMPANY));
    }

    public function test_company_id_parameter_in_request_is_ignored(): void
    {
        $a = $this->createCompany();
        $b = $this->createCompany();
        $owner = $this->createOwner($a);

        $this->actingAs($owner)
            ->get(route('company.dashboard', ['company_id' => $b->id]))
            ->assertOk()
            ->assertSee($a->name)
            ->assertDontSee($b->name);
    }

    public function test_cannot_switch_to_other_company_or_its_branch(): void
    {
        $a = $this->createCompany();
        $b = $this->createCompany();
        $owner = $this->createOwner($a);

        $this->actingAs($owner)->post(route('tenant.company.switch'), ['company' => $b->uuid])->assertForbidden();
        $this->actingAs($owner)->post(route('tenant.branch.switch'), ['branch' => $this->headOffice($b)->uuid])->assertForbidden();
    }

    public function test_cannot_register_pos_device_on_another_companys_branch(): void
    {
        $a = $this->createCompany();
        $b = $this->createCompany();
        $owner = $this->createOwner($a);
        $this->actingAsTenant($owner, $a);

        Livewire::test(PosDeviceManager::class)
            ->set('name', 'Smuggled till')
            ->set('branch', $this->headOffice($b)->uuid)
            ->call('save')
            ->assertHasErrors('branch');

        $this->assertSame(0, PosDevice::withoutTenancy()->count());
    }

    public function test_service_rejects_branch_from_another_company(): void
    {
        $a = $this->createCompany();
        $b = $this->createCompany();

        $this->expectException(TenantViolationException::class);
        app(PosDeviceService::class)->register($a, $this->headOffice($b), 'Till', 80);
    }

    public function test_cannot_revoke_another_companys_device(): void
    {
        $a = $this->createCompany();
        $b = $this->createCompany();
        $owner = $this->createOwner($a);
        $foreignDevice = PosDevice::factory()->create(['branch_id' => $this->headOffice($b)->id]);
        $this->actingAsTenant($owner, $a);

        Livewire::test(PosDeviceManager::class)
            ->call('revoke', $foreignDevice->uuid)
            ->assertForbidden();

        $this->assertSame(PosDeviceStatus::Active, PosDevice::withoutTenancy()->find($foreignDevice->id)->status);
    }

    public function test_owner_can_register_device_on_own_branch(): void
    {
        $a = $this->createCompany();
        $owner = $this->createOwner($a);
        $this->actingAsTenant($owner, $a);

        Livewire::test(PosDeviceManager::class)
            ->set('name', 'Till 1')
            ->set('branch', $this->headOffice($a)->uuid)
            ->set('receiptPaperWidth', 58)
            ->call('save')
            ->assertHasNoErrors();

        $device = PosDevice::withoutTenancy()->sole();
        $this->assertSame($a->id, $device->company_id);
        $this->assertSame(58, $device->receipt_paper_width);
        $this->assertSame($owner->id, $device->registered_by);
    }

    public function test_branch_manager_cannot_register_device_on_unassigned_branch(): void
    {
        $a = $this->createCompany();
        $mine = $this->createBranch($a);
        $manager = $this->addMember($a, SystemRole::BranchManager, [$mine]);
        $this->actingAsTenant($manager, $a);

        Livewire::test(PosDeviceManager::class)
            ->set('name', 'Till X')
            ->set('branch', $this->headOffice($a)->uuid)
            ->call('save')
            ->assertHasErrors('branch');

        $this->assertSame(0, PosDevice::withoutTenancy()->count());
    }

    public function test_staff_service_rejects_foreign_branches_and_platform_roles(): void
    {
        $a = $this->createCompany();
        $b = $this->createCompany();
        $service = app(StaffService::class);

        try {
            $service->addMember($a, User::factory()->create(), Role::system(SystemRole::Cashier->value), [$this->headOffice($b)->id]);
            $this->fail('Foreign branch must be rejected.');
        } catch (TenantViolationException) {
            // expected
        }

        $this->expectException(TenantViolationException::class);
        $service->addMember($a, User::factory()->create(), Role::system(SystemRole::PlatformSuperAdmin->value));
    }
}
