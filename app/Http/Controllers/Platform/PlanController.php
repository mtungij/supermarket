<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use Illuminate\View\View;

class PlanController extends Controller
{
    public function index(): View
    {
        $this->authorize('platform.plans.manage');

        return view('platform.plans', [
            'plans' => SubscriptionPlan::query()->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }
}
