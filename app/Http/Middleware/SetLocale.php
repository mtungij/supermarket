<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = array_keys(config('saas.supported_locales'));

        $locale = collect([
            $request->hasSession() ? $request->session()->get('locale') : null,
            $request->user()?->locale,
            config('app.locale'),
        ])->first(fn ($l) => is_string($l) && in_array($l, $supported, true));

        if ($locale !== null) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
