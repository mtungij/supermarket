<?php

namespace App\Http\Controllers;

use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TenantSwitchController extends Controller
{
    public function __construct(private readonly TenantResolver $resolver) {}

    public function company(Request $request): RedirectResponse
    {
        $validated = $request->validate(['company' => ['required', 'uuid']]);

        $this->resolver->switchCompany($request->user(), $validated['company'], $request->session());

        return redirect()->route('dashboard');
    }

    public function branch(Request $request, TenantContext $context): RedirectResponse
    {
        $validated = $request->validate(['branch' => ['required', 'uuid']]);

        $this->resolver->switchBranch($context->membership(), $validated['branch'], $request->session());

        return back();
    }
}
