<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Controller;
use App\Models\PosDevice;
use App\Support\Tenancy\TenantContext;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(TenantContext $context): View
    {
        $branch = $context->branch();

        return view('branch.dashboard', [
            'branch' => $branch,
            'deviceCount' => $branch ? PosDevice::query()->where('branch_id', $branch->id)->count() : 0,
        ]);
    }
}
