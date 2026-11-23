<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Production: Traefik terminates TLS and forwards to the app over HTTP with
 * X-Forwarded-Proto: https. Before the fix the login form posted to
 * http://shoppilot.site/login ("This form is not secure", autofill off).
 */
class HttpsBehindProxyTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    /** Request as Traefik forwards it: from the Docker network, plain HTTP, forwarded headers. */
    private const TRAEFIK = ['REMOTE_ADDR' => '172.18.0.5'];

    private const FORWARDED = ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'shoppilot.site', 'X-Forwarded-Port' => '443', 'X-Forwarded-For' => '41.59.10.20'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'production', 'app.url' => 'https://shoppilot.site', 'app.force_https' => true]);
    }

    protected function tearDown(): void
    {
        URL::forceScheme(null);
        URL::forceRootUrl(null);
        parent::tearDown();
    }

    private function viaTraefik(string $method, string $uri, array $data = [])
    {
        $test = $this->withServerVariables(self::TRAEFIK)->withHeaders(self::FORWARDED);

        return $method === 'GET' ? $test->get('http://shoppilot.site'.$uri) : $test->post('http://shoppilot.site'.$uri, $data);
    }

    public function test_login_page_form_and_links_are_https_with_secure_cookies(): void
    {
        $response = $this->viaTraefik('GET', '/login')->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('action="https://shoppilot.site/login"', $html);
        $this->assertStringNotContainsString('action="http://', $html);
        $this->assertStringNotContainsString('href="http://shoppilot', $html);
        $this->assertStringNotContainsString('src="http://shoppilot', $html);
        $this->assertStringContainsString('https://shoppilot.site/forgot-password', $html);
        $this->assertStringContainsString('https://shoppilot.site/register', $html);
        $this->assertStringContainsString('name="_token"', $html);                       // CSRF kept

        foreach ($response->headers->getCookies() as $cookie) {
            $this->assertTrue($cookie->isSecure(), $cookie->getName().' must be Secure');
            $this->assertSame('lax', $cookie->getSameSite());
        }
        $session = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));
        $this->assertTrue($session->isHttpOnly());
        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_redirects_stay_on_https(): void
    {
        $this->viaTraefik('GET', '/')->assertRedirect('https://shoppilot.site/login');

        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->viaTraefik('POST', '/login', ['email' => $owner->email, 'password' => 'password'])
            ->assertRedirect('https://shoppilot.site/dashboard');
        $this->assertAuthenticatedAs($owner);
        $this->viaTraefik('GET', '/dashboard')->assertRedirect('https://shoppilot.site/company');
    }

    public function test_role_redirects_and_password_reset_still_work_on_https(): void
    {
        $company = $this->createCompany();
        $cashier = $this->addMember($company, SystemRole::Cashier, [$this->headOffice($company)]);
        $this->actingAs($cashier);
        $this->viaTraefik('GET', '/dashboard')->assertRedirect('https://shoppilot.site/pos');

        $this->post('/logout');
        $this->viaTraefik('GET', '/forgot-password')->assertOk()->assertSee('action="https://shoppilot.site/forgot-password"', false);
    }

    public function test_real_client_ip_is_used_for_login_throttling(): void
    {
        // behind the proxy the client IP comes from X-Forwarded-For (trusted proxy)...
        $this->viaTraefik('GET', '/login');
        $this->assertSame('41.59.10.20', request()->ip());

        // ...but a direct (non-proxy) caller cannot spoof it or the scheme
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.4.4'])->withHeaders(self::FORWARDED)->get('http://shoppilot.site/login');
        $this->assertSame('8.8.4.4', request()->ip());
        $this->assertFalse(request()->isSecure());

        // throttling (5/min per email + client IP) still applies
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        for ($i = 0; $i < 5; $i++) {
            $this->viaTraefik('POST', '/login', ['email' => $owner->email, 'password' => 'wrong']);
        }
        $this->viaTraefik('POST', '/login', ['email' => $owner->email, 'password' => 'password'])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_local_development_is_unchanged(): void
    {
        config(['app.env' => 'local', 'app.url' => 'http://localhost', 'app.force_https' => false]);
        $response = $this->get('http://localhost/login')->assertOk();

        $this->assertStringContainsString('action="http://localhost/login"', $response->getContent());
        $response->assertHeaderMissing('Strict-Transport-Security');
    }
}
