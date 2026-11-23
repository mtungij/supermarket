<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * "/" is the login entry point; signed-in users land in their role's area.
 * Authentication behaviour (Fortify) is unchanged.
 */
class LoginEntryTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    public function test_guest_root_goes_to_login_and_landing_page_moves_to_welcome(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk();                    // no loop: login renders for guests
        $this->get('/welcome')->assertOk()->assertSee('Register your supermarket');
        $this->get(route('register'))->assertOk();
    }

    /** Follows redirects from "/" and returns the final path (fails on loops). */
    private function landing(User $user): string
    {
        $path = '/';
        for ($hops = 0; $hops < 6; $hops++) {
            $response = $this->actingAs($user)->get($path);
            if (! $response->isRedirect()) {
                $response->assertOk();

                return $path;
            }
            $path = parse_url($response->headers->get('Location'), PHP_URL_PATH) ?: '/';
        }
        $this->fail('Redirect loop from / for '.$user->email);
    }

    public function test_signed_in_users_land_in_their_role_area_without_loops(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $branch = $this->headOffice($company);

        $this->assertSame('/company', $this->landing($owner));
        $this->assertSame('/pos', $this->landing($this->addMember($company, SystemRole::Cashier, [$branch])));
        $this->assertSame('/inventory/stock', $this->landing($this->addMember($company, SystemRole::StoreKeeper, [$branch])));
        $this->assertSame('/branch', $this->landing($this->addMember($company, SystemRole::BranchManager, [$branch])));
        $this->assertSame('/platform', $this->landing(User::factory()->platformAdmin()->create()));

        // a signed-in user opening /login is sent on, not shown the form again
        $this->actingAs($owner)->get(route('login'))->assertRedirect();
    }

    public function test_cashier_logs_in_straight_to_the_pos_without_a_shift_screen(): void
    {
        $company = $this->createCompany();
        $cashier = $this->addMember($company, SystemRole::Cashier, [$this->headOffice($company)]);

        $this->post(route('login'), ['email' => $cashier->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($cashier);
        $this->get('/dashboard')->assertRedirect(route('pos'));
        $this->get(route('pos'))->assertOk()->assertDontSee('shift', false);
    }

    public function test_login_security_is_unchanged(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);

        // CSRF token + remember me present
        $this->get(route('login'))->assertSee('name="_token"', false)->assertSee('name="remember"', false);

        // wrong password -> error, input kept (email), not logged in
        $this->from(route('login'))->post(route('login'), ['email' => $owner->email, 'password' => 'wrong'])
            ->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->get(route('login'))->assertSee($owner->email);

        // throttling (5 per minute) still applies
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login'), ['email' => $owner->email, 'password' => 'wrong']);
        }
        $this->post(route('login'), ['email' => $owner->email, 'password' => 'password'])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_remember_me_and_password_reset_still_work(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);

        $this->post(route('login'), ['email' => $owner->email, 'password' => 'password', 'remember' => 'on'])->assertRedirect('/dashboard');
        $this->assertNotNull($owner->fresh()->remember_token);
        $this->post(route('logout'));

        Notification::fake();
        $this->get(route('login'))->assertSee(route('password.request'), false);
        $this->get(route('password.request'))->assertOk();
        $this->post(route('password.email'), ['email' => $owner->email])->assertSessionHasNoErrors();
        Notification::assertSentTo($owner, ResetPassword::class);
    }

    public function test_login_page_layout_assets_and_languages(): void
    {
        $page = $this->get(route('login'))->assertOk()
            ->assertSee('data-login-hero', false)->assertSee('lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]', false)
            ->assertSee('images/login/supermarket-1280.webp', false)->assertSee('images/login/supermarket-1920.webp', false)
            ->assertSee('images/login/supermarket-1280.jpg', false)
            ->assertSee('from-[#0b1b34]', false)                          // gradient fallback behind the photo
            ->assertSee('data-password-toggle', false)->assertSee('autocomplete="current-password"', false)
            ->assertSee('min-h-dvh', false)
            ->assertSee('Welcome back')->assertSee('Point of sale')->assertSee('Inventory')->assertSee('Multi-branch')->assertSee('Sales reports')
            ->assertSee('Forgot your password?')->assertSee('Register your supermarket')
            ->assertDontSee('unsplash', false)->assertDontSee('wikimedia', false);   // no third-party image at runtime

        foreach (['supermarket-768.webp', 'supermarket-1280.webp', 'supermarket-1920.webp', 'supermarket-1280.jpg'] as $file) {
            $this->assertFileExists(public_path('images/login/'.$file));
            $this->assertLessThan(150_000, filesize(public_path('images/login/'.$file)));
        }
        $this->assertStringContainsString('CC0', file_get_contents(public_path('images/login/CREDITS.md')));

        $this->post(route('locale.update'), ['locale' => 'sw']);
        $this->get(route('login'))->assertOk()
            ->assertSee('Karibu tena')->assertSee('Barua pepe')->assertSee('Nenosiri')->assertSee('Nikumbuke')
            ->assertSee('Umesahau nenosiri?')->assertSee('Sajili supamaketi yako')->assertSee('Matawi mengi');

        // validation messages in Kiswahili too
        $this->from(route('login'))->post(route('login'), ['email' => '', 'password' => '']);
        $this->get(route('login'))->assertSee('form-error', false);
    }
}
