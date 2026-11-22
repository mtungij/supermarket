<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the current company/branch from the authenticated user's
 * active memberships. Must run after `auth`. Users without any active
 * membership are sent to a holding page (platform admins to /platform).
 */
class ResolveTenantContext
{
    public function __construct(private readonly TenantResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_if($user === null, 401);

        if ($this->resolver->resolve($user, $request->session()) === null) {
            if ($request->expectsJson()) {
                abort(403, __('You do not have access to any active company.'));
            }

            return $user->isPlatformAdmin()
                ? redirect()->route('platform.dashboard')
                : redirect()->route('no-company');
        }

        return $next($request);
    }
}
