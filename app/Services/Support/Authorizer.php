<?php

namespace App\Services\Support;

use App\Models\Branch;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Server-side permission and branch checks performed INSIDE domain
 * services, so sensitive operations stay protected whichever entry point
 * (Livewire, controller, job) calls them.
 */
class Authorizer
{
    public function __construct(private readonly TenantContext $context) {}

    /** @throws AuthorizationException */
    public function permission(User $user, string $permission): void
    {
        Gate::forUser($user)->authorize($permission);
    }

    /** @throws AuthorizationException */
    public function branch(User $user, Branch|int $branch): void
    {
        $membership = $this->context->membership();

        if ($membership === null || $membership->user_id !== $user->id || ! $membership->canAccessBranch($branch)) {
            throw new AuthorizationException(__('You do not have access to that branch.'));
        }
    }

    public function can(User $user, string $permission): bool
    {
        return Gate::forUser($user)->allows($permission);
    }
}
