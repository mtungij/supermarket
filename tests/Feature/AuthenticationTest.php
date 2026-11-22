<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    public function test_login_screen_renders(): void
    {
        $this->get('/login')->assertOk()->assertSee('Log in');
    }

    public function test_active_member_can_log_in_and_is_routed_to_their_area(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);

        $this->post('/login', ['email' => $owner->email, 'password' => 'password'])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($owner);
        $this->assertNotNull($owner->fresh()->last_login_at);
        $this->get('/dashboard')->assertRedirect(route('company.dashboard'));
    }

    public function test_branch_staff_are_routed_to_the_branch_dashboard(): void
    {
        $company = $this->createCompany();
        $supervisor = $this->addMember($company, SystemRole::Supervisor, [$this->headOffice($company)]);

        $this->actingAs($supervisor)->get('/dashboard')->assertRedirect(route('branch.dashboard'));
        $this->actingAs($supervisor)->get(route('branch.dashboard'))->assertOk()->assertSee($company->name.' HQ');
    }

    public function test_cashiers_go_straight_to_the_pos(): void
    {
        $company = $this->createCompany();
        $cashier = $this->addMember($company, SystemRole::Cashier, [$this->headOffice($company)]);

        $this->actingAs($cashier)->get('/dashboard')->assertRedirect(route('pos'));
    }

    public function test_session_is_regenerated_on_login(): void
    {
        $user = User::factory()->create();
        $this->startSession();
        $before = session()->getId();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertNotSame($before, session()->getId());
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = User::factory()->inactive()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_user_deactivated_mid_session_is_logged_out(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);

        $this->actingAs($owner)->get(route('company.dashboard'))->assertOk();

        $owner->forceFill(['is_active' => false])->save();

        $this->actingAs($owner->fresh())->get(route('company.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_user_can_log_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_guests_cannot_reach_tenant_or_platform_areas(): void
    {
        $this->get(route('company.dashboard'))->assertRedirect(route('login'));
        $this->get(route('branch.dashboard'))->assertRedirect(route('login'));
        $this->get(route('platform.dashboard'))->assertRedirect(route('login'));
    }

    public function test_password_reset_link_can_be_requested_and_used(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->get('/forgot-password')->assertOk();
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->get('/reset-password/'.$token.'?email='.urlencode($user->email))->assertOk();

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'BrandNew123pass',
            'password_confirmation' => 'BrandNew123pass',
        ])->assertSessionHasNoErrors()->assertRedirect('/login');

        $this->assertTrue(Hash::check('BrandNew123pass', $user->fresh()->password));
    }

    public function test_user_without_membership_sees_holding_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('no-company'));
        $this->actingAs($user)->get(route('company.dashboard'))->assertRedirect(route('no-company'));
    }
}
