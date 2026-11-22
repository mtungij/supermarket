<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies Laravel's `verified` middleware only when
 * SAAS_EMAIL_VERIFICATION is enabled.
 */
class EnsureEmailIsVerifiedWhenEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('saas.email_verification')) {
            return $next($request);
        }

        return app(EnsureEmailIsVerified::class)->handle($request, $next);
    }
}
