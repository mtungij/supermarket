<?php

namespace App\Http\Controllers;

use App\Support\Tenancy\TenantResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sends each user to the area matching who they are:
 * platform admins -> /platform, company-wide staff -> /company,
 * cashiers -> /pos, store keepers -> /inventory/stock, other branch
 * staff -> /branch.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, TenantResolver $resolver): RedirectResponse
    {
        $user = $request->user();

        if ($user->isPlatformAdmin()) {
            return redirect()->route('platform.dashboard');
        }

        $membership = $resolver->resolve($user, $request->session());

        if ($membership === null) {
            return redirect()->route('no-company');
        }

        // Cashiers (POS access without management rights) go straight to the
        // POS. No shift screen exists.
        if (! $membership->all_branches && $user->can('pos.access') && ! $user->can('branches.view')) {
            return redirect()->route('pos');
        }

        // Branch staff whose job is stock only (store keepers: inventory but no
        // POS and no reports) start in their inventory area.
        if (! $membership->all_branches && $user->can('inventory.view') && ! $user->can('pos.access') && ! $user->can('reports.view')) {
            return redirect()->route('inventory.stock');
        }

        return $membership->all_branches
            ? redirect()->route('company.dashboard')
            : redirect()->route('branch.dashboard');
    }
}
