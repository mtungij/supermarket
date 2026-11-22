<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Models\Branch;
use App\Models\PosDevice;
use App\Services\BranchService;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    public function test_tenant_scope_fails_closed_without_a_tenant_context(): void
    {
        $this->createCompany();
        $this->createCompany();

        $this->assertSame(0, Branch::query()->count(), 'No tenant context must mean no rows, never all rows.');
        $this->assertSame(2, Branch::withoutTenancy()->count());
        $this->assertSame(2, app(TenantContext::class)->withoutTenancy(fn () => Branch::query()->count()));
    }

    public function test_queries_only_return_the_current_companys_rows(): void
    {
        $a = $this->createCompany();
        $b = $this->createCompany();
        $this->createBranch($b, ['name' => 'Foreign Branch']);
        PosDevice::factory()->create(['branch_id' => $this->headOffice($b)->id]);
        $owner = $this->createOwner($a);

        $this->actingAsTenant($owner, $a);

        $this->assertSame([$a->id], Branch::query()->pluck('company_id')->unique()->values()->all());
        $this->assertSame(0, PosDevice::query()->count());
        $this->assertNull(Branch::query()->find($this->headOffice($b)->id));
    }

    public function test_branch_listing_only_shows_own_company(): void
    {
        $a = $this->createCompany();
        $b = $this->createCompany();
        $this->createBranch($b, ['name' => 'Foreign Branch']);
        $owner = $this->createOwner($a);

        $this->actingAs($owner)->get(route('company.branches.index'))
            ->assertOk()
            ->assertSee($a->name.' HQ')
            ->assertDontSee('Foreign Branch')
            ->assertDontSee($b->name.' HQ');
    }

    public function test_staff_listing_only_shows_own_company_members(): void
    {
        $a = $this->createCompany();
        $b = $this->createCompany();
        $owner = $this->createOwner($a);
        $foreigner = $this->addMember($b, SystemRole::Cashier);

        $this->actingAs($owner)->get(route('company.staff.index'))
            ->assertOk()
            ->assertSee($owner->email)
            ->assertDontSee($foreigner->email);
    }

    public function test_cannot_create_a_record_for_another_company_from_tenant_context(): void
    {
        $a = $this->createCompany();
        $b = $this->createCompany();
        $owner = $this->createOwner($a);
        $this->actingAsTenant($owner, $a);

        $this->expectException(TenantViolationException::class);
        $branch = new Branch(['name' => 'Smuggled', 'code' => 'X1']);
        $branch->company_id = $b->id;
        $branch->save();
    }

    public function test_service_refuses_to_write_into_another_company_from_tenant_context(): void
    {
        $a = $this->createCompany(['max_branches' => 10]);
        $b = $this->createCompany(['max_branches' => 10]);
        $owner = $this->createOwner($a);
        $this->actingAsTenant($owner, $a);

        try {
            app(BranchService::class)->create($b, ['name' => 'Smuggled']);
            $this->fail('Cross-tenant write should be refused.');
        } catch (TenantViolationException) {
            // expected
        }

        $this->assertSame(1, Branch::withoutTenancy()->where('company_id', $b->id)->count());
    }

    public function test_company_id_cannot_be_changed_after_creation(): void
    {
        $a = $this->createCompany();
        $b = $this->createCompany();
        $branch = $this->headOffice($a);

        $this->expectException(TenantViolationException::class);
        $branch->company_id = $b->id;
        $branch->save();
    }

    public function test_company_id_is_not_mass_assignable(): void
    {
        $this->assertNotContains('company_id', (new Branch)->getFillable());
        $this->assertNotContains('company_id', (new PosDevice)->getFillable());
        $this->assertNotContains('branch_id', (new PosDevice)->getFillable());
    }
}
