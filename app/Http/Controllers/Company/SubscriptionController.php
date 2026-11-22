<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Services\SubscriptionLimiter;
use App\Support\Tenancy\TenantContext;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    public function __invoke(TenantContext $context, SubscriptionLimiter $limiter): View
    {
        $company = $context->company();

        return view('company.subscription', [
            'subscription' => $company->currentSubscription(),
            'usage' => $limiter->summary($company),
        ]);
    }
}
