<?php

namespace App\Services;

use App\Enums\PlanLimit;
use App\Enums\SystemRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantViolationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Manages company memberships.
 *
 * Rules for editing staff (updateMember / resetPassword / sendResetLink):
 *  - only members of the SAME company, never platform administrators;
 *  - no escalation: a non-owner can only manage members whose role, and
 *    assign roles whose permissions, are within their own; only owners
 *    manage owners or grant the owner role; branch-scoped managers only
 *    grant branches they can access themselves (other branches are kept);
 *  - you cannot change your own role or deactivate yourself;
 *  - the company always keeps at least one ACTIVE owner;
 *  - a person's account details (name, email, phone, password) belong to
 *    the person: they are only editable here when the person works for
 *    this company alone (otherwise another company would be affected);
 *  - changes are logged as document events on the membership; nothing
 *    historical (sales, stock movements, purchases) is touched.
 */
class StaffService
{
    public function __construct(private readonly SubscriptionLimiter $limiter) {}

    /**
     * @param  list<int>  $branchIds  Branches for branch-scoped roles.
     */
    public function addMember(Company $company, User $user, Role $role, array $branchIds = [], ?User $invitedBy = null): CompanyUser
    {
        if (! $role->isAssignableIn($company)) {
            // Rejects platform roles and other companies' custom roles.
            throw new TenantViolationException('Role is not assignable in this company.');
        }

        if ($user->isPlatformAdmin()) {
            throw ValidationException::withMessages([
                'email' => __('Platform administrator accounts cannot be added to a company.'),
            ]);
        }

        return DB::transaction(function () use ($company, $user, $role, $branchIds, $invitedBy) {
            $company = $this->limiter->lockCompany($company);

            if (CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['email' => __('This user is already a member of the company.')]);
            }

            $this->limiter->ensureCanAdd($company, PlanLimit::Staff, 'email');

            $validBranchIds = $company->branches()->whereIn('id', $branchIds)->pluck('id');

            if ($validBranchIds->count() !== count(array_unique($branchIds))) {
                throw new TenantViolationException('One or more branches do not belong to this company.');
            }

            $membership = CompanyUser::query()->forceCreate([
                'company_id' => $company->id,
                'user_id' => $user->id,
                'role_id' => $role->id,
                'is_active' => true,
                'all_branches' => $role->all_branches,
                'invited_by' => $invitedBy?->id,
                'joined_at' => now(),
            ]);

            $membership->branches()->attach(
                $validBranchIds->mapWithKeys(fn ($id) => [$id => ['company_id' => $company->id]])->all()
            );

            return $membership;
        });
    }

    /** Whether $actor (their membership) may edit $target at all. */
    public function canManage(CompanyUser $actor, CompanyUser $target): bool
    {
        if ($actor->company_id !== $target->company_id || $target->user?->isPlatformAdmin()) {
            return false;
        }
        if ($actor->isOwner()) {
            return true;
        }

        return ! $target->isOwner() && $this->withinAuthority($actor, $target->role);
    }

    /**
     * @param  array{name?: ?string, email?: ?string, phone?: ?string, role: string, branch_ids?: list<int>, all_branches?: bool, is_active: bool}  $data
     */
    public function updateMember(CompanyUser $target, CompanyUser $actor, array $data): CompanyUser
    {
        $this->guardTarget($actor, $target);
        $company = Company::query()->findOrFail($target->company_id);

        $role = Role::query()->where('slug', $data['role'] ?? '')->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id))->first();
        if ($role === null || ! $role->isAssignableIn($company)) {
            throw ValidationException::withMessages(['role' => __('Select a valid role.')]);
        }
        if (! $actor->isOwner() && ($role->slug === SystemRole::CompanyOwner->value || ! $this->withinAuthority($actor, $role))) {
            throw ValidationException::withMessages(['role' => __('You cannot give a role with more rights than your own.')]);
        }

        $isSelf = $actor->id === $target->id;
        $active = (bool) $data['is_active'];

        return DB::transaction(function () use ($company, $target, $actor, $data, $role, $active, $isSelf) {
            $company = $this->limiter->lockCompany($company);          // serialises owner / limit checks
            $target = CompanyUser::query()->where('company_id', $company->id)->lockForUpdate()->with(['role', 'user', 'branches'])->findOrFail($target->id);
            $before = ['role' => $target->role->slug, 'active' => $target->is_active, 'all' => $target->all_branches, 'branches' => $target->branches->pluck('name')->sort()->values()->all()];

            // last active owner
            $staysOwner = $role->slug === SystemRole::CompanyOwner->value && $active;
            if ($target->isOwner() && $target->is_active && ! $staysOwner) {
                $otherOwners = CompanyUser::query()->where('company_id', $company->id)->whereKeyNot($target->id)->where('is_active', true)
                    ->whereHas('role', fn ($r) => $r->where('slug', SystemRole::CompanyOwner->value))->exists();
                if (! $otherOwners) {
                    throw ValidationException::withMessages([$active ? 'role' : 'is_active' => __('The company must keep at least one active owner. Make someone else an owner first.')]);
                }
            }

            if ($isSelf && $role->id !== $target->role_id) {
                throw ValidationException::withMessages(['role' => __('You cannot change your own role. Ask another owner.')]);
            }
            if ($isSelf && ! $active) {
                throw ValidationException::withMessages(['is_active' => __('You cannot deactivate your own account.')]);
            }

            // re-activation counts against the plan's staff limit
            if ($active && ! $target->is_active) {
                $this->limiter->ensureCanAdd($company, PlanLimit::Staff, 'is_active');
            }

            // branches: only within the actor's own access; branches outside it are kept
            $requested = array_values(array_unique(array_map('intval', $data['branch_ids'] ?? [])));
            $valid = $company->branches()->whereIn('id', $requested)->pluck('id')->all();
            if (count($valid) !== count($requested)) {
                throw new TenantViolationException('One or more branches do not belong to this company.');
            }
            $actorBranches = $actor->all_branches ? null : $actor->branches()->pluck('branches.id')->all();
            $branchIds = $actorBranches === null ? $valid
                : array_values(array_unique(array_merge(array_intersect($valid, $actorBranches), array_diff($target->branches->pluck('id')->all(), $actorBranches))));
            $allBranches = $role->all_branches || ((bool) ($data['all_branches'] ?? false) && $actor->all_branches);
            if (! $allBranches && $branchIds === []) {
                throw ValidationException::withMessages(['branches' => __('Assign at least one branch for this role.')]);
            }

            $this->updateIdentity($target->user, $company, $data);

            $target->forceFill([
                'role_id' => $role->id,
                'all_branches' => $allBranches,
                'is_active' => $active,
                'deactivated_at' => $active ? null : ($target->deactivated_at ?? now()),
            ])->save();
            $target->branches()->sync(collect($allBranches ? [] : $branchIds)->mapWithKeys(fn ($id) => [$id => ['company_id' => $company->id]])->all());

            // audit trail
            $by = $actor->user;
            if ($before['role'] !== $role->slug) {
                $target->recordEvent('role_changed', $before['role'], $role->slug, $by);
            }
            $after = $allBranches ? [] : Branch::withoutTenancy()->whereIn('id', $branchIds)->orderBy('name')->pluck('name')->all();
            if ($before['all'] !== $allBranches || ($allBranches === false && $before['branches'] !== $after)) {
                $target->recordEvent('branches_changed', null, null, $by,
                    ($before['all'] ? __('All branches') : (implode(', ', $before['branches']) ?: '—')).' → '.($allBranches ? __('All branches') : implode(', ', $after)));
            }
            if ($before['active'] !== $active) {
                $target->recordEvent($active ? 'activated' : 'deactivated', $before['active'] ? 'active' : 'inactive', $active ? 'active' : 'inactive', $by);
            }

            return $target->fresh(['role', 'user', 'branches']);
        });
    }

    /** Admin sets a new password (person works only for this company). Other sessions are signed out. */
    public function resetPassword(CompanyUser $target, CompanyUser $actor, string $password): void
    {
        $this->guardTarget($actor, $target);
        $user = $target->user;
        if ($this->worksElsewhere($user, $target->company_id)) {
            throw ValidationException::withMessages(['password' => __('This person also works for another company. Send them a reset link instead.')]);
        }

        DB::transaction(function () use ($target, $actor, $user, $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();   // hashed by the model cast
            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }
            $target->recordEvent('password_reset', null, null, $actor->user, __('New password set by an administrator.'));
        });
    }

    /** Email the person a standard password-reset link (works for any member). */
    public function sendResetLink(CompanyUser $target, CompanyUser $actor): string
    {
        $this->guardTarget($actor, $target);
        $status = Password::sendResetLink(['email' => $target->user->email]);
        $target->recordEvent('password_reset_link', null, null, $actor->user, __('Reset link e-mailed.'));

        return $status;
    }

    public function worksElsewhere(User $user, int $companyId): bool
    {
        return CompanyUser::query()->where('user_id', $user->id)->where('company_id', '!=', $companyId)->exists();
    }

    private function guardTarget(CompanyUser $actor, CompanyUser $target): void
    {
        if ($actor->company_id !== $target->company_id) {
            throw new TenantViolationException('Staff member belongs to another company.');
        }
        if (! Gate::forUser($actor->user)->allows('staff.manage')) {
            throw new AuthorizationException(__('This action is unauthorized.'));
        }
        if (! $this->canManage($actor, $target)) {
            throw new AuthorizationException(__('You cannot manage this staff member.'));
        }
    }

    /** Role permissions are a subset of the actor's own. */
    private function withinAuthority(CompanyUser $actor, ?Role $role): bool
    {
        if ($role === null) {
            return false;
        }
        $mine = $actor->role->permissions()->pluck('slug')->all();

        return array_diff($role->permissions()->pluck('slug')->all(), $mine) === [];
    }

    private function updateIdentity(User $user, Company $company, array $data): void
    {
        $changes = [];
        $name = array_key_exists('name', $data) ? trim((string) $data['name']) : $user->name;
        $email = array_key_exists('email', $data) ? Str::lower(trim((string) $data['email'])) : $user->email;
        $phone = array_key_exists('phone', $data) ? (trim((string) $data['phone']) ?: null) : $user->phone;

        foreach (['name' => $name, 'email' => $email, 'phone' => $phone] as $field => $value) {
            if ((string) $user->{$field} !== (string) $value) {
                $changes[$field] = $value;
            }
        }
        if ($changes === []) {
            return;
        }
        if ($this->worksElsewhere($user, $company->id)) {
            throw ValidationException::withMessages([array_key_first($changes) => __('This person also works for another company, so only they can change their name, email or phone.')]);
        }
        if ($name === '') {
            throw ValidationException::withMessages(['name' => __('Enter the full name.')]);
        }
        if (isset($changes['email']) && User::query()->where('email', $email)->whereKeyNot($user->id)->exists()) {
            throw ValidationException::withMessages(['email' => __('This email address is already used by another account.')]);
        }

        $user->forceFill($changes + (isset($changes['email']) ? ['email_verified_at' => now()] : []))->save();
    }

    public function deactivate(CompanyUser $membership): void
    {
        $membership->forceFill(['is_active' => false, 'deactivated_at' => now()])->save();
    }
}
