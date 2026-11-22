<?php

namespace App\View\Composers;

use App\Support\Navigation\Navigation;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantResolver;
use Illuminate\View\View;

/**
 * Supplies navigation and company/branch selector data to the
 * authenticated layouts. Selector options come only from the user's
 * active memberships and accessible branches.
 */
class AppLayoutComposer
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantResolver $resolver,
    ) {}

    public function compose(View $view): void
    {
        $user = auth()->user();
        $area = str($view->name())->afterLast('.')->toString(); // platform|company|branch

        $navigation = match ($area) {
            'platform' => Navigation::platform($user),
            'company' => Navigation::company($user),
            default => Navigation::branch($user),
        };

        $companies = collect();
        $branches = collect();

        if ($area !== 'platform' && ($membership = $this->context->membership()) !== null) {
            $companies = $this->resolver->activeMemberships($user)
                ->map(fn ($m) => ['uuid' => $m->company->uuid, 'name' => $m->company->name]);
            $branches = $this->resolver->accessibleBranches($membership)
                ->map(fn ($b) => ['uuid' => $b->uuid, 'name' => $b->name]);
        }

        $view->with([
            'area' => $area,
            'navigation' => $navigation,
            'currentCompany' => $this->context->company(),
            'currentBranch' => $this->context->branch(),
            'currentRole' => $this->context->membership()?->role?->name,
            'companyOptions' => $companies,
            'branchOptions' => $branches,
        ]);
    }
}
