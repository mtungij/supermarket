<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Production behind Traefik (TLS terminated at the proxy, app reached over
 * plain HTTP): with app.force_https on,
 *  - every generated URL (form actions, redirects, links, assets) is
 *    https:// on APP_URL's host, even if a proxy header goes missing;
 *  - session / XSRF cookies are Secure;
 *  - HTTPS responses carry Strict-Transport-Security.
 *
 * It deliberately does NOT redirect http -> https itself: Traefik does that,
 * and an app-level redirect would loop if proxy headers were ever missing.
 * Runs before StartSession so the cookie settings apply to this request.
 */
class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.force_https')) {
            return $next($request);
        }

        URL::forceScheme('https');
        URL::forceRootUrl(preg_replace('#^http://#', 'https://', rtrim((string) config('app.url'), '/')));
        config(['session.secure' => true]);

        $response = $next($request);

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
