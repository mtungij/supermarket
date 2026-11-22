<?php

namespace App\Livewire\Concerns;

use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantResolver;

/**
 * Guarantees a validated tenant context on EVERY Livewire request
 * (initial render and each subsequent update).
 *
 * The ResolveTenantContext persistent middleware normally handles this;
 * this hook is defence in depth so a component can never run with a
 * missing or stale tenant if middleware configuration changes.
 */
trait RequiresTenant
{
    public function bootRequiresTenant(): void
    {
        $context = app(TenantContext::class);

        if ($context->hasCompany()) {
            return;
        }

        $user = auth()->user();

        abort_if($user === null, 401);
        abort_if(app(TenantResolver::class)->resolve($user, session()->driver()) === null, 403);
    }
}
