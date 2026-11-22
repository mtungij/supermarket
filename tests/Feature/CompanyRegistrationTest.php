<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Enums\SystemRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Mwanga Supermarket',
            'company_email' => 'info@mwanga.test',
            'company_phone' => '+255 712 345 678',
            'business_address' => 'Plot 12, Samora Avenue, Dar es Salaam',
            'branch_name' => 'Kariakoo Branch',
            'owner_name' => 'Asha Mohamed',
            'owner_email' => 'asha@mwanga.test',
            'password' => 'Secret123pass',
            'password_confirmation' => 'Secret123pass',
        ], $overrides);
    }

    public function test_registration_screen_renders(): void
    {
        $this->get('/register')->assertOk()->assertSee('Company name');
    }

    public function test_company_registration_creates_company_branch_owner_membership_and_subscription(): void
    {
        $response = $this->post('/register', $this->payload());

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticated();

        $company = Company::query()->where('email', 'info@mwanga.test')->firstOrFail();
        $owner = User::query()->where('email', 'asha@mwanga.test')->firstOrFail();

        // Company with default settings
        $this->assertSame('TZS', $company->currency);
        $this->assertSame('Africa/Dar_es_Salaam', $company->timezone);
        $this->assertSame('TZ', $company->country_code);
        $this->assertSame(80, $company->setting('receipt_paper_width'));
        $this->assertTrue($company->isActive());
        $this->assertSame($owner->id, $company->owner_user_id);

        // Initial head-office branch
        $branches = Branch::withoutTenancy()->where('company_id', $company->id)->get();
        $this->assertCount(1, $branches);
        $this->assertSame('Kariakoo Branch', $branches->first()->name);
        $this->assertTrue($branches->first()->is_head_office);

        // Owner membership with the Company Owner role
        $membership = CompanyUser::query()->where('company_id', $company->id)->where('user_id', $owner->id)->firstOrFail();
        $this->assertSame(SystemRole::CompanyOwner->value, $membership->role->slug);
        $this->assertTrue($membership->is_active);
        $this->assertTrue($membership->all_branches);

        // Initial (trial) subscription
        $subscription = Subscription::withoutTenancy()->where('company_id', $company->id)->firstOrFail();
        $this->assertSame(SubscriptionStatus::Trialing, $subscription->status);
        $this->assertSame('trial', $subscription->plan->code);
        $this->assertNotNull($subscription->ends_at);
        $this->assertTrue($subscription->isUsable());

        // Owner is hashed, active and NOT a platform admin
        $this->assertNotSame('Secret123pass', $owner->password);
        $this->assertTrue(password_verify('Secret123pass', $owner->password));
        $this->assertTrue($owner->is_active);
        $this->assertFalse($owner->isPlatformAdmin());
        $this->assertNull($owner->platform_role_id);

        $this->get('/dashboard')->assertRedirect(route('company.dashboard'));
        $this->get(route('company.dashboard'))->assertOk()->assertSee('Mwanga Supermarket');
    }

    public function test_public_registration_cannot_create_a_platform_super_admin(): void
    {
        $platformRole = Role::system(SystemRole::PlatformSuperAdmin->value);

        $this->post('/register', $this->payload([
            'is_platform_admin' => true,
            'platform_role_id' => $platformRole->id,
            'role' => SystemRole::PlatformSuperAdmin->value,
            'role_id' => $platformRole->id,
        ]))->assertRedirect('/dashboard');

        $owner = User::query()->where('email', 'asha@mwanga.test')->firstOrFail();
        $this->assertNull($owner->platform_role_id);
        $this->assertFalse($owner->isPlatformAdmin());
        $this->assertSame(SystemRole::CompanyOwner->value, $owner->memberships()->first()->role->slug);
        $this->get('/platform')->assertForbidden();
    }

    public function test_required_fields_are_validated(): void
    {
        $this->post('/register', [])->assertSessionHasErrors([
            'company_name', 'company_email', 'company_phone', 'business_address',
            'branch_name', 'owner_name', 'owner_email', 'password',
        ]);

        $this->assertSame(0, Company::query()->count());
        $this->assertGuest();
    }

    public function test_onboarding_is_atomic_and_rolls_back_on_failure(): void
    {
        // Remove the owner role so onboarding fails AFTER the company and
        // branch rows have been inserted.
        Role::system(SystemRole::CompanyOwner->value)->delete();

        $this->withoutExceptionHandling();

        try {
            $this->post('/register', $this->payload());
            $this->fail('Registration should have failed.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(0, Company::withTrashed()->count());
        $this->assertSame(0, Branch::withoutTenancy()->count());
        $this->assertSame(0, Subscription::withoutTenancy()->count());
        $this->assertNull(User::query()->where('email', 'asha@mwanga.test')->first());
    }

    public function test_existing_account_is_associated_only_with_its_correct_password(): void
    {
        $existing = User::factory()->create(['email' => 'asha@mwanga.test', 'password' => 'Original123pass']);

        // Wrong password: rejected, nothing created.
        $this->post('/register', $this->payload())->assertSessionHasErrors('owner_email');
        $this->assertSame(0, Company::query()->count());
        $this->assertGuest();

        // Correct password: new company is attached to the existing account.
        $this->post('/register', $this->payload([
            'password' => 'Original123pass',
            'password_confirmation' => 'Original123pass',
        ]))->assertRedirect('/dashboard');

        $this->assertSame(1, User::query()->where('email', 'asha@mwanga.test')->count());
        $this->assertSame(1, $existing->memberships()->count());
        $this->assertAuthenticatedAs($existing);
    }

    public function test_platform_admin_account_cannot_be_used_as_company_owner(): void
    {
        User::factory()->platformAdmin()->create(['email' => 'asha@mwanga.test', 'password' => 'Secret123pass']);

        $this->post('/register', $this->payload())->assertSessionHasErrors('owner_email');
        $this->assertSame(0, Company::query()->count());
    }
}
