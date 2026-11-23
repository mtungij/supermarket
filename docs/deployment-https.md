# HTTPS behind Traefik (shoppilot.site)

## Why it matters
Traefik terminates TLS and forwards requests to the app container (Apache) over plain HTTP. Laravel must **trust Traefik's `X-Forwarded-*` headers**. Otherwise it believes every request is `http://`, and:

- it renders `<form action="http://shoppilot.site/login">`. Chrome then shows "This form is not secure", turns autofill off, and warns that "the information you're about to submit is not secure";
- `/` redirects to `http://…/login`, costing an extra 301 at Traefik;
- session and XSRF cookies are sent without `Secure`;
- every visitor appears to come from Traefik's IP, which weakens login throttling.

## What the app does now
- **Trusted proxies** (`bootstrap/app.php` + `AppServiceProvider`):
  - `X-Forwarded-For/Host/Port/Proto` are trusted from `TRUSTED_PROXIES` only.
  - The default is private networks (`10/8, 172.16/12, 192.168/16, loopback, fc00::/7`), which covers Docker networks.
  - A direct, non-proxy caller cannot spoof the scheme or its IP.
- **`App\Http\Middleware\ForceHttps`** is global and runs before sessions. When `FORCE_HTTPS` is on:
  - all generated URLs are `https://` on `APP_URL`'s host;
  - cookies are `Secure`;
  - HTTPS responses get `Strict-Transport-Security: max-age=31536000`.
  - It does **not** redirect HTTP to HTTPS itself. Traefik does that, and an in-app redirect could loop if headers were missing.
- **Session cookie:** `Secure` by default when `APP_URL` is `https://`; `HttpOnly`; `SameSite=Lax`.

## Production `.env`
```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://shoppilot.site
SESSION_SECURE_COOKIE=true
FORCE_HTTPS=true
# Only if Traefik reaches the app from a NON-private address:
# TRUSTED_PROXIES=203.0.113.10
```

## Traefik
Already in place on shoppilot.site, and still required: HTTP→HTTPS redirect (`http://shoppilot.site/*` returns 301 to `https://`).

A typical label set for the app service:
```yaml
labels:
  - traefik.enable=true
  - traefik.http.routers.shoppilot.rule=Host(`shoppilot.site`)
  - traefik.http.routers.shoppilot.entrypoints=websecure
  - traefik.http.routers.shoppilot.tls.certresolver=letsencrypt
  - traefik.http.routers.shoppilot-http.rule=Host(`shoppilot.site`)
  - traefik.http.routers.shoppilot-http.entrypoints=web
  - traefik.http.routers.shoppilot-http.middlewares=shoppilot-https
  - traefik.http.middlewares.shoppilot-https.redirectscheme.scheme=https
  - traefik.http.middlewares.shoppilot-https.redirectscheme.permanent=true
```
Traefik sends `X-Forwarded-Proto: https` by default. Do not strip it.

## Deploy
1. Deploy the code.
2. Set the `.env` values above.
3. Run `php artisan optimize:clear && php artisan optimize`. Cached config keeps the old values until you do.
4. Restart PHP/Apache (or the container).

## Verify
```
curl -sI https://shoppilot.site/              # location: https://shoppilot.site/login
curl -s  https://shoppilot.site/login | grep -o 'action="[^"]*"'   # only https:// actions
curl -sI https://shoppilot.site/login | grep -i 'set-cookie\|strict-transport'   # secure; httponly; HSTS
```
In Chrome, the padlock shows no warning, and password autofill works.
