<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Services\SubscriptionLimiter;
use App\Support\Tenancy\TenantContext;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(TenantContext $context, SubscriptionLimiter $limiter): View
    {
        $company = $context->company();

        return view('company.dashboard', [
            'company' => $company,
            'subscription' => $company->currentSubscription(),
            'usage' => $limiter->summary($company),
        ]);
    }
}
