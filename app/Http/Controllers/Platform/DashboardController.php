<?php

namespace App\Http\Controllers\Platform;

use App\Enums\CompanyStatus;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('platform.dashboard', [
            'stats' => [
                'companies' => Company::query()->count(),
                'active_companies' => Company::query()->where('status', CompanyStatus::Active->value)->count(),
                'usable_subscriptions' => Subscription::withoutTenancy()
                    ->whereIn('status', SubscriptionStatus::usableValues())
                    ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
                    ->count(),
                'users' => User::query()->count(),
            ],
        ]);
    }
}
