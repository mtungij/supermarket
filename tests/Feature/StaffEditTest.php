<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Livewire\Company\StaffIndex;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\DocumentEvent;
use App\Models\User;
use App\Services\BranchService;
use App\Services\StaffService;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantViolationException;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Edit / update staff and reset passwords (Company → Staff). */
class StaffEditTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    private Company $company;

    private User $owner;

    private Branch $hq;

    private Branch $mbezi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany();
        $this->owner = $this->createOwner($this->company);
        $this->actingAsTenant($this->owner, $this->company);
        $this->hq = $this->headOffice($this->company);
        $this->mbezi = app(BranchService::class)->create($this->company, ['name' => 'Mbezi Beach']);
    }

    private function membership(User $user, ?Company $company = null): CompanyUser
    {
        return CompanyUser::query()->where('company_id', ($company ?? $this->company)->id)->where('user_id', $user->id)->firstOrFail();
    }

    private function as(User $user)
    {
        $this->actingAsTenant($user, $this->company);

        return Livewire::test(StaffIndex::class);
    }

    public function test_owner_updates_details_role_branches_and_status_with_audit_trail(): void
    {
        $cashier = $this->addMember($this->company, SystemRole::Cashier, [$this->hq]);
        $m = $this->membership($cashier);

        $this->as($this->owner)->assertSee('Actions')->assertSee('data-edit-staff="'.$m->id.'"', false)
            ->call('edit', $m->id)->assertSet('editing', true)->assertSet('email', $cashier->email)->assertSet('role', 'cashier')
            ->set('name', 'Neema Juma')->set('email', 'NEEMA@Example.com')->set('phone', '+255 712 345 678')
            ->set('role', 'store_keeper')->set('branches', [$this->hq->uuid, $this->mbezi->uuid])
            ->call('save')->assertHasNoErrors()->assertSet('editing', false)->assertSee('Staff member updated.')->assertSee('Neema Juma');

        $cashier->refresh();
        $m->refresh();
        $this->assertSame(['Neema Juma', 'neema@example.com', '+255 712 345 678'], [$cashier->name, $cashier->email, $cashier->phone]);
        $this->assertSame('store_keeper', $m->role->slug);
        $this->assertEqualsCanonicalizing([$this->hq->id, $this->mbezi->id], $m->branches()->pluck('branches.id')->all());
        $this->assertSame(['role_changed', 'branches_changed'], $m->events()->pluck('event')->all());
        $this->assertSame(['cashier', 'store_keeper'], [$m->events()->first()->from_status, $m->events()->first()->to_status]);

        // the new permissions apply on the very next request
        $this->actingAs($cashier)->get('/dashboard')->assertRedirect(route('inventory.stock'));
        $this->actingAs($cashier)->get(route('pos'))->assertForbidden();

        // deactivation: no access from the next request, history kept
        $this->as($this->owner)->call('edit', $m->id)->set('isActive', false)->call('save')->assertHasNoErrors();
        $this->assertFalse($m->fresh()->is_active);
        $this->assertSame('deactivated', $m->events()->reorder()->latest('id')->value('event'));
        $this->actingAs($cashier)->get('/dashboard')->assertRedirect(route('no-company'));
    }

    public function test_email_must_be_unique_but_may_stay_unchanged(): void
    {
        $a = $this->addMember($this->company, SystemRole::Cashier, [$this->hq]);
        $b = $this->addMember($this->company, SystemRole::Cashier, [$this->hq]);

        $this->as($this->owner)->call('edit', $this->membership($a)->id)->set('email', strtoupper($b->email))->call('save')
            ->assertHasErrors('email')->assertSet('editing', true);
        $this->assertNotSame($b->email, $a->fresh()->email);

        $this->as($this->owner)->call('edit', $this->membership($a)->id)->set('name', 'Same Email')->call('save')->assertHasNoErrors();
        $this->assertSame('Same Email', $a->fresh()->name);
    }

    public function test_other_companies_staff_are_invisible_and_cannot_be_edited(): void
    {
        app(TenantContext::class)->clear();
        $other = $this->createCompany();
        $otherOwner = $this->createOwner($other);
        $foreign = $this->membership($otherOwner, $other);

        $this->as($this->owner)->assertDontSee($otherOwner->email)->call('edit', $foreign->id)->assertNotFound();
        $this->as($this->owner)->call('askPassword', $foreign->id)->assertNotFound();

        $this->expectException(TenantViolationException::class);
        app(StaffService::class)->updateMember($foreign, $this->membership($this->owner), ['role' => 'cashier', 'is_active' => true]);
    }

    public function test_last_active_owner_is_protected(): void
    {
        $me = $this->membership($this->owner);
        $this->as($this->owner)->call('edit', $me->id)->assertSet('role', 'company_owner')
            ->set('isActive', false)->call('save')->assertHasErrors('is_active');
        $this->as($this->owner)->call('edit', $me->id)->set('role', 'branch_manager')->call('save')->assertHasErrors('role');
        $this->assertTrue($me->fresh()->is_active);
        $this->assertSame('company_owner', $me->fresh()->role->slug);

        // with a second owner, one owner may demote the other (but never themselves)
        $second = $this->addMember($this->company, SystemRole::CompanyOwner);
        $this->as($this->owner)->call('edit', $this->membership($second)->id)->set('role', 'branch_manager')->set('branches', [$this->hq->uuid])->call('save')->assertHasNoErrors();
        $this->assertSame('branch_manager', $this->membership($second)->role->slug);
        $this->as($this->owner)->call('edit', $me->id)->set('role', 'cashier')->call('save')->assertHasErrors('role');
    }

    public function test_no_role_escalation_by_branch_managers(): void
    {
        $manager = $this->addMember($this->company, SystemRole::BranchManager, [$this->hq]);
        $cashier = $this->addMember($this->company, SystemRole::Cashier, [$this->hq]);
        $owner2 = $this->addMember($this->company, SystemRole::CompanyOwner);

        $this->as($manager)->assertDontSee('data-edit-staff="'.$this->membership($owner2)->id.'"', false)
            ->assertSee('data-edit-staff="'.$this->membership($cashier)->id.'"', false)
            ->call('edit', $this->membership($owner2)->id)->assertForbidden();
        $this->as($manager)->call('edit', $this->membership($cashier)->id)
            ->assertDontSeeHtml('value="company_owner"')->assertDontSeeHtml('value="accountant"')   // rights the manager lacks
            ->assertSeeHtml('value="supervisor"');

        $this->as($manager)->call('edit', $this->membership($cashier)->id)->set('role', 'company_owner')->call('save')->assertHasErrors('role');
        $this->as($manager)->call('edit', $this->membership($cashier)->id)->set('role', 'accountant')->call('save')->assertHasErrors('role');
        $this->assertSame('cashier', $this->membership($cashier)->role->slug);

        // allowed: a role within the manager's own rights, in the manager's own branch
        $this->as($manager)->call('edit', $this->membership($cashier)->id)->set('role', 'supervisor')->call('save')->assertHasNoErrors();
        $this->assertSame('supervisor', $this->membership($cashier)->role->slug);
    }

    public function test_branch_scoped_manager_only_changes_branches_they_manage(): void
    {
        $manager = $this->addMember($this->company, SystemRole::BranchManager, [$this->hq]);
        $cashier = $this->addMember($this->company, SystemRole::Cashier, [$this->mbezi]);   // works at Mbezi (owner's choice)
        $m = $this->membership($cashier);

        // manager adds HQ, tries to grant "all branches" and to remove Mbezi (outside their scope)
        $this->as($manager)->call('edit', $m->id)->set('branches', [$this->hq->uuid])->set('allBranches', true)->call('save')->assertHasNoErrors();
        $m->refresh();
        $this->assertFalse($m->all_branches);
        $this->assertEqualsCanonicalizing([$this->hq->id, $this->mbezi->id], $m->branches()->pluck('branches.id')->all());

        // a branch-scoped role needs at least one branch
        $this->as($this->owner)->call('edit', $m->id)->set('branches', [])->call('save')->assertHasErrors('branches');
    }

    public function test_platform_admin_memberships_are_never_editable(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $m = CompanyUser::factory()->role(SystemRole::Cashier)->create(['company_id' => $this->company->id, 'user_id' => $admin->id]);

        $this->as($this->owner)->call('edit', $m->id)->assertForbidden();
        $this->assertFalse(app(StaffService::class)->canManage($this->membership($this->owner), $m));
    }

    public function test_reset_password_signs_out_and_never_logs_the_password(): void
    {
        $cashier = $this->addMember($this->company, SystemRole::Cashier, [$this->hq]);
        $m = $this->membership($cashier);
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $cashier->id, 'ip_address' => '1.1.1.1', 'user_agent' => 't', 'payload' => 'x', 'last_activity' => time()]);
        config(['session.driver' => 'database']);

        $this->as($this->owner)->call('askPassword', $m->id)->assertSet('resettingPassword', true)->assertSee('Reset password for')
            ->set('password', 'short')->set('password_confirmation', 'short')->call('resetPassword')->assertHasErrors('password')
            ->set('password', 'NewSecret123pass')->set('password_confirmation', 'NewSecret123pass')->call('resetPassword')->assertHasNoErrors()
            ->assertSee('They have been signed out of other devices');

        $this->assertTrue(Hash::check('NewSecret123pass', $cashier->fresh()->password));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $cashier->id)->count());
        $event = $m->events()->sole();
        $this->assertSame('password_reset', $event->event);
        $this->assertStringNotContainsString('NewSecret123pass', (string) $event->notes);
        $this->assertSame(0, DocumentEvent::query()->where('notes', 'like', '%NewSecret%')->count());
    }

    public function test_shared_accounts_keep_their_identity_but_role_can_change(): void
    {
        $shared = $this->addMember($this->company, SystemRole::Cashier, [$this->hq]);
        app(TenantContext::class)->clear();
        $other = $this->createCompany();
        $this->addMember($other, SystemRole::Cashier, [$this->headOffice($other)], $shared);   // also works elsewhere
        $m = $this->membership($shared);

        $this->as($this->owner)->call('edit', $m->id)->assertSet('identityLocked', true)->assertSee('also works for another company')
            ->set('email', 'hijack@example.com')->call('save')->assertHasErrors('email');
        $this->assertNotSame('hijack@example.com', $shared->fresh()->email);

        $this->as($this->owner)->call('edit', $m->id)->set('role', 'supervisor')->call('save')->assertHasNoErrors();
        $this->assertSame('supervisor', $m->fresh()->role->slug);
        $this->assertSame('cashier', $this->membership($shared, $other)->role->slug);   // other company untouched

        Notification::fake();
        $this->as($this->owner)->call('askPassword', $m->id)->set('password', 'NewSecret123pass')->set('password_confirmation', 'NewSecret123pass')
            ->call('resetPassword')->assertHasErrors('password');
        $this->as($this->owner)->call('askPassword', $m->id)->call('sendResetLink')->assertSee('A password reset link was sent');
        Notification::assertSentTo($shared, ResetPassword::class);
    }

    public function test_staff_without_manage_permission_see_no_actions_and_page_is_responsive_and_translated(): void
    {
        $supervisor = $this->addMember($this->company, SystemRole::Supervisor, [$this->hq]);
        $this->actingAs($this->owner)->get(route('company.staff.index'))->assertOk()
            ->assertSee('data-stack', false)->assertSee('Actions')->assertSee('Reset password')->assertSee('Phone');

        $this->as($supervisor)->assertDontSee('data-edit-staff="', false)->call('edit', $this->membership($this->owner)->id)->assertForbidden();

        $this->owner->forceFill(['locale' => 'sw'])->save();
        $this->actingAs($this->owner->fresh())->get(route('company.staff.index'))->assertOk()
            ->assertSee('Vitendo')->assertSee('Hariri')->assertSee('Badilisha nenosiri');
    }
}
