<?php

namespace App\Livewire\Company;

use App\Actions\Fortify\PasswordValidationRules;
use App\Enums\RoleScope;
use App\Enums\SystemRole;
use App\Livewire\Concerns\InteractsWithTenant;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\StaffService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Minimal staff onboarding (e.g. cashiers who go straight to the POS).
 * New accounts get the password set here; an existing account (same
 * email) is simply added to this company with the chosen role.
 * Plan staff limits and branch rules are enforced by StaffService.
 */
class AddStaffMember extends Component
{
    use InteractsWithTenant, PasswordValidationRules;

    public bool $open = false;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $role = 'cashier';

    /** @var list<string> branch uuids */
    public array $branches = [];

    public function mount(): void
    {
        $this->authorize('staff.manage');
        $this->branches = array_filter([(string) app(TenantContext::class)->branch()?->uuid]);
    }

    public function save(StaffService $staff): void
    {
        $this->authorize('staff.manage');
        $existing = User::query()->where('email', Str::lower(trim($this->email)))->first();

        $this->validate([
            'name' => [$existing ? 'nullable' : 'required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => $existing ? ['nullable'] : $this->passwordRules(),
            'role' => ['required', Rule::in($this->assignableRoles())],
            'branches' => ['array'],
            'branches.*' => ['uuid'],
        ]);

        $role = Role::system($this->role);
        $branchIds = $this->accessibleBranches()->whereIn('uuid', $this->branches)->pluck('id')->all();

        if (! $role->all_branches && $branchIds === []) {
            $this->addError('branches', __('Assign at least one branch for this role.'));

            return;
        }

        DB::transaction(function () use ($existing, $role, $branchIds, $staff) {
            $user = $existing;
            if ($user === null) {
                $user = new User(['name' => trim($this->name), 'email' => Str::lower(trim($this->email)), 'password' => $this->password]);
                $user->forceFill(['is_active' => true, 'email_verified_at' => now()])->save();
            }

            $staff->addMember($this->company(), $user, $role, $role->all_branches ? [] : $branchIds, $this->actor());
        });

        $this->reset(['name', 'email', 'password', 'password_confirmation', 'open']);
        session()->flash('status', __('Staff member added.'));
        $this->redirectRoute('company.staff.index');
    }

    /** @return list<string> */
    private function assignableRoles(): array
    {
        return collect(SystemRole::tenantRoles())->reject(fn ($r) => $r === SystemRole::CompanyOwner)->map->value->values()->all();
    }

    public function render(): View
    {
        return view('livewire.company.add-staff-member', [
            'roles' => Role::query()->whereNull('company_id')->where('scope', RoleScope::Tenant->value)->whereIn('slug', $this->assignableRoles())->orderBy('name')->get(['slug', 'name', 'all_branches']),
            'branchOptions' => $this->accessibleBranches(),
        ]);
    }
}
