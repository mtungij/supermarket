<?php

namespace App\Models;

use App\Enums\SystemRole;
use App\Models\Concerns\HasDocumentEvents;
use App\Support\Tenancy\CompanyScope;
use Database\Factories\CompanyUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A user's membership in a company (role, status, branch access).
 *
 * Deliberately not covered by the tenant global scope: memberships are
 * how the tenant context is *resolved*. Always query memberships through
 * the user ($user->memberships) or with an explicit company_id.
 *
 * All attributes are guarded; memberships are created by
 * App\Services\StaffService and App\Services\CompanyOnboardingService.
 */
class CompanyUser extends Pivot
{
    /** @use HasFactory<CompanyUserFactory> */
    use HasDocumentEvents, HasFactory;

    protected $table = 'company_user';

    public $incrementing = true;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'user_id' => 'integer',
            'role_id' => 'integer',
            'is_active' => 'boolean',
            'all_branches' => 'boolean',
            'joined_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Branches explicitly assigned to this membership. The pivot carries
     * company_id and composite foreign keys enforce same-company.
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_company_user', 'company_user_id', 'branch_id')
            ->withoutGlobalScopes([CompanyScope::class])
            ->withPivot('company_id')
            ->withTimestamps();
    }

    public function isOwner(): bool
    {
        return $this->role?->slug === SystemRole::CompanyOwner->value;
    }

    public function canAccessBranch(Branch|int $branch): bool
    {
        $branch = $branch instanceof Branch
            ? $branch
            : Branch::withoutTenancy()->find($branch);

        if ($branch === null || $branch->company_id !== $this->company_id || ! $this->is_active) {
            return false;
        }

        if ($this->all_branches) {
            return true;
        }

        return $this->branches()->whereKey($branch->id)->exists();
    }
}
