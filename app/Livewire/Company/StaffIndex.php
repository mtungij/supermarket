<?php

namespace App\Livewire\Company;

use App\Actions\Fortify\PasswordValidationRules;
use App\Enums\RoleScope;
use App\Enums\SystemRole;
use App\Livewire\Concerns\InteractsWithTenant;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Services\StaffService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Staff list with Edit and Reset password actions (Livewire, no reload).
 * Every rule (tenant, no escalation, last owner, shared accounts) lives in
 * StaffService; this component only collects input. Memberships are
 * always looked up inside the current company.
 */
class StaffIndex extends Component
{
    use InteractsWithTenant, PasswordValidationRules, WithPagination;

    #[Locked]
    public ?int $editingId = null;

    public bool $editing = false;

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $role = '';

    /** @var list<string> branch uuids */
    public array $branches = [];

    public bool $allBranches = false;

    public bool $isActive = true;

    #[Locked]
    public bool $identityLocked = false;

    #[Locked]
    public ?int $passwordFor = null;

    public bool $resettingPassword = false;

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $this->authorize('staff.view');
    }

    private function actorMembership(): CompanyUser
    {
        return app(TenantContext::class)->membership();
    }

    /** A member of THIS company only (another company's id is simply not found). */
    private function member(int $id): CompanyUser
    {
        return CompanyUser::query()->where('company_id', $this->company()->id)->with(['user', 'role', 'branches'])->find($id) ?? abort(404);
    }

    public function edit(int $id, StaffService $staff): void
    {
        $this->authorize('staff.manage');
        $m = $this->member($id);
        abort_unless($staff->canManage($this->actorMembership(), $m), 403);

        $this->resetValidation();
        $this->editingId = $m->id;
        $this->fill([
            'name' => $m->user->name, 'email' => $m->user->email, 'phone' => (string) $m->user->phone,
            'role' => $m->role->slug, 'branches' => $m->branches->pluck('uuid')->all(),
            'allBranches' => $m->all_branches, 'isActive' => $m->is_active,
        ]);
        $this->identityLocked = $staff->worksElsewhere($m->user, $m->company_id);
        $this->editing = true;
    }

    public function save(StaffService $staff): void
    {
        $this->authorize('staff.manage');
        $m = $this->member((int) $this->editingId);

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s]*$/'],
            'role' => ['required', 'string'],
            'branches' => ['array'],
            'branches.*' => ['uuid'],
        ], [], ['phone' => __('Phone')]);

        $staff->updateMember($m, $this->actorMembership(), [
            'name' => $this->name, 'email' => $this->email, 'phone' => $this->phone,
            'role' => $this->role,
            'branch_ids' => $this->company()->branches()->whereIn('uuid', $this->branches)->pluck('id')->all(),
            'all_branches' => $this->allBranches,
            'is_active' => $this->isActive,
        ]);

        $this->editing = false;
        $this->editingId = null;
        session()->flash('status', __('Staff member updated.'));
    }

    public function askPassword(int $id, StaffService $staff): void
    {
        $this->authorize('staff.manage');
        $m = $this->member($id);
        abort_unless($staff->canManage($this->actorMembership(), $m), 403);
        $this->resetValidation();
        $this->reset(['password', 'password_confirmation']);
        $this->passwordFor = $m->id;
        $this->identityLocked = $staff->worksElsewhere($m->user, $m->company_id);
        $this->resettingPassword = true;
    }

    public function resetPassword(StaffService $staff): void
    {
        $this->authorize('staff.manage');
        $m = $this->member((int) $this->passwordFor);
        $this->validate(['password' => $this->passwordRules()]);

        $staff->resetPassword($m, $this->actorMembership(), $this->password);
        $this->reset(['password', 'password_confirmation', 'resettingPassword', 'passwordFor']);
        session()->flash('status', __('Password changed for :name. They have been signed out of other devices.', ['name' => $m->user->name]));
    }

    public function sendResetLink(StaffService $staff): void
    {
        $this->authorize('staff.manage');
        $m = $this->member((int) $this->passwordFor);
        $status = $staff->sendResetLink($m, $this->actorMembership());
        $this->reset(['password', 'password_confirmation', 'resettingPassword', 'passwordFor']);
        session()->flash('status', $status === Password::RESET_LINK_SENT
            ? __('A password reset link was sent to :email.', ['email' => $m->user->email])
            : __($status));
    }

    public function render(StaffService $staff): View
    {
        $actor = $this->actorMembership();
        $members = CompanyUser::query()->where('company_id', $this->company()->id)
            ->with(['user:id,name,email,phone,is_active', 'role:id,name,slug', 'branches:id,name'])
            ->orderByDesc('is_active')->orderBy('joined_at')->paginate(25);
        $canManage = auth()->user()->can('staff.manage');

        $roles = Role::query()->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $this->company()->id))
            ->where('scope', RoleScope::Tenant->value)->with('permissions:id,slug')->orderBy('name')->get();
        if (! $actor->isOwner()) {
            $mine = $actor->role->permissions()->pluck('slug')->all();
            $roles = $roles->reject(fn ($r) => $r->slug === SystemRole::CompanyOwner->value || array_diff($r->permissions->pluck('slug')->all(), $mine) !== []);
        }

        $editingMember = $this->editingId ? CompanyUser::query()->where('company_id', $this->company()->id)->find($this->editingId) : null;

        return view('livewire.company.staff-index', [
            'members' => $members,
            'manageable' => $canManage ? $members->getCollection()->mapWithKeys(fn ($m) => [$m->id => $staff->canManage($actor, $m)])->all() : [],
            'roles' => $roles,
            'selectedRole' => $roles->firstWhere('slug', $this->role),
            'branchOptions' => $this->accessibleBranches(),
            'actorAllBranches' => $actor->all_branches,
            'isSelf' => $this->editingId === $actor->id,
            'history' => $editingMember ? $editingMember->events()->reorder()->with('user:id,name')->latest('id')->limit(6)->get() : collect(),
            'passwordMember' => $this->passwordFor ? CompanyUser::query()->where('company_id', $this->company()->id)->with('user:id,name,email')->find($this->passwordFor) : null,
        ]);
    }
}
