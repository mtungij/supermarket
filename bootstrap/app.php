<?php

use App\Http\Middleware\EnsureEmailIsVerifiedWhenEnabled;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ResolveTenantContext;
use App\Http\Middleware\SetLocale;
use App\Support\Tenancy\TenantViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            EnsureUserIsActive::class,
            SetLocale::class,
        ]);

        $middleware->alias([
            'tenant' => ResolveTenantContext::class,
            'platform' => EnsurePlatformAdmin::class,
            'verified.optional' => EnsureEmailIsVerifiedWhenEnabled::class,
        ]);

        // The tenant context must exist BEFORE route model binding runs,
        // so bound models ({branch}, ...) are resolved through the
        // tenant-scoped query (another company's id => 404).
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveTenantContext::class,
        );

        // Deactivated users are logged out before anything else runs.
        $middleware->prependToPriorityList(
            before: ResolveTenantContext::class,
            prepend: EnsureUserIsActive::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Cross-tenant attempts are reported and answered with a generic 403.
        $exceptions->render(function (TenantViolationException $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => __('This action is unauthorized.')], 403)
                : abort(403);
        });
    })->create();
