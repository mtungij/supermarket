<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\CompanyUser;
use App\Support\Tenancy\TenantContext;
use Illuminate\View\View;

class StaffController extends Controller
{
    public function index(TenantContext $context): View
    {
        return view('company.staff', [
            'members' => CompanyUser::query()
                ->where('company_id', $context->companyId())
                ->with(['user:id,name,email,is_active', 'role:id,name', 'branches:id,name'])
                ->orderByDesc('is_active')
                ->orderBy('joined_at')
                ->paginate(25),
        ]);
    }
}
