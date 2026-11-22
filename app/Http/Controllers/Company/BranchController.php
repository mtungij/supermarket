<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\View\View;

class BranchController extends Controller
{
    /**
     * {branch} is resolved through the tenant-scoped model, so another
     * company's branch UUID yields 404; the `can:view,branch` middleware
     * additionally enforces branch-level access.
     */
    public function show(Branch $branch): View
    {
        return view('company.branch-show', [
            'branch' => $branch->loadCount('posDevices'),
        ]);
    }
}
