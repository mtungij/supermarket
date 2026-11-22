<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\View\View;

class CompanyController extends Controller
{
    public function index(): View
    {
        $this->authorize('platform.companies.view');

        return view('platform.companies', [
            'companies' => Company::query()
                ->with('owner:id,name,email')
                ->withCount(['branches', 'memberships'])
                ->latest()
                ->paginate(25),
        ]);
    }
}
