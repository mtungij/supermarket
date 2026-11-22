<?php

namespace Tests\Feature;

use App\Enums\PlanLimit;
use App\Enums\PosDeviceStatus;
use App\Enums\SystemRole;
use App\Exceptions\PlanLimitExceededException;
use App\Livewire\Company\BranchManager;
use App\Models\Branch;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BranchService;
use App\Services\PosDeviceService;
use App\Services\StaffService;
use App\Services\SubscriptionLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class SubscriptionLimitsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    public function test_branch_limit_is_enforced(): void
    {
        $company = $this->createCompany(['max_branches' => 2]);
        $service = app(BranchService::class);

        $service->create($company, ['name' => 'Second']);
        $this->assertSame(2, Branch::withoutTenancy()->where('company_id', $company->id)->count());

        $this->expectException(PlanLimitExceededException::class);
        $service->create($company, ['name' => 'Third']);
    }

    public function test_staff_limit_is_enforced(): void
    {
        $company = $this->createCompany(['max_staff' => 2]);
        $this->createOwner($company); // 1 of 2
        $service = app(StaffService::class);
        $cashier = Role::system(SystemRole::Cashier->value);

        $service->addMember($company, User::factory()->create(), $cashier, [$this->headOffice($company)->id]); // 2 of 2

        $this->expectException(PlanLimitExceededException::class);
        $service->addMember($company, User::factory()->create(), $cashier);
    }

    public function test_inactive_memberships_do_not_count_toward_staff_limit(): void
    {
        $company = $this->createCompany(['max_staff' => 1]);
        $this->addMember($company, SystemRole::Cashier, active: false);

        $member = app(StaffService::class)->addMember($company, User::factory()->create(), Role::system(SystemRole::Cashier->value));
        $this->assertTrue($member->is_active);
    }

    public function test_pos_device_limit_is_enforced_and_revoked_devices_do_not_count(): void
    {
        $company = $this->createCompany(['max_pos_devices' => 1]);
        $branch = $this->headOffice($company);
        $service = app(PosDeviceService::class);

        $first = $service->register($company, $branch, 'Till 1', 80);

        try {
            $service->register($company, $branch, 'Till 2', 58);
            $this->fail('Device limit should have been enforced.');
        } catch (PlanLimitExceededException) {
            // expected
        }

        $service->revoke($first);
        $this->assertSame(PosDeviceStatus::Revoked, $first->fresh()->status);

        $second = $service->register($company, $branch, 'Till 2', 58);
        $this->assertSame(58, $second->receipt_paper_width);
    }

    public function test_null_limits_mean_unlimited(): void
    {
        $company = $this->createCompany(['max_branches' => null]);
        $service = app(BranchService::class);

        foreach (range(1, 5) as $i) {
            $service->create($company, ['name' => "Branch {$i}"]);
        }

        $this->assertNull(app(SubscriptionLimiter::class)->limit($company, PlanLimit::Branches));
        $this->assertSame(6, Branch::withoutTenancy()->where('company_id', $company->id)->count());
    }

    public function test_expired_subscription_blocks_growth(): void
    {
        $company = $this->createCompany(['max_branches' => 10]);
        Subscription::withoutTenancy()->where('company_id', $company->id)->update([
            'status' => 'expired',
            'ends_at' => now()->subDay(),
        ]);

        $limiter = app(SubscriptionLimiter::class);
        $this->assertNull($company->currentSubscription());
        $this->assertSame(0, $limiter->limit($company, PlanLimit::Branches));
        $this->assertFalse($limiter->canAdd($company, PlanLimit::Branches));

        $this->expectException(PlanLimitExceededException::class);
        app(BranchService::class)->create($company, ['name' => 'Blocked']);
    }

    public function test_limit_error_is_shown_in_branch_manager(): void
    {
        $company = $this->createCompany(['max_branches' => 1]);
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);

        Livewire::test(BranchManager::class)
            ->set('name', 'Over the limit')
            ->call('save')
            ->assertHasErrors('name');

        $this->assertSame(1, Branch::withoutTenancy()->where('company_id', $company->id)->count());
    }

    public function test_owner_can_create_branch_within_limit_via_livewire(): void
    {
        $company = $this->createCompany(['max_branches' => 3]);
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);

        Livewire::test(BranchManager::class)
            ->set('name', 'Mbezi Branch')
            ->set('code', 'mbz')
            ->call('save')
            ->assertHasNoErrors();

        $branch = Branch::withoutTenancy()->where('company_id', $company->id)->where('code', 'MBZ')->first();
        $this->assertNotNull($branch);
        $this->assertSame('Mbezi Branch', $branch->name);
    }
}
