<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class PageRenderingTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    public function test_public_pages_render(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get('/welcome')->assertOk()->assertSee('Register your supermarket');
        $this->get('/login')->assertOk();
        $this->get('/register')->assertOk();
        $this->get('/forgot-password')->assertOk();
    }

    public function test_company_area_pages_render_for_owner(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);

        foreach (['company.dashboard', 'company.branches.index', 'company.staff.index', 'company.pos-devices.index', 'company.subscription', 'branch.dashboard'] as $route) {
            $this->actingAs($owner)->get(route($route))->assertOk();
        }
    }

    public function test_platform_pages_render_with_real_counts_only(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        $this->actingAs($admin)->get(route('platform.dashboard'))->assertOk()->assertSee('Platform overview');
        $this->actingAs($admin)->get(route('platform.plans.index'))->assertOk()->assertSee('Free Trial');
        $this->actingAs($admin)->get(route('platform.companies.index'))->assertOk()->assertSee('No companies have registered yet.');
    }

    public function test_no_company_page_renders(): void
    {
        $this->actingAs(User::factory()->create())->get(route('no-company'))->assertOk()->assertSee('No active company');
    }

    public function test_kiswahili_locale_can_be_selected(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);

        $this->actingAs($owner)->from(route('company.dashboard'))
            ->post(route('locale.update'), ['locale' => 'sw'])
            ->assertRedirect(route('company.dashboard'));

        $this->assertSame('sw', $owner->fresh()->locale);
        $this->actingAs($owner->fresh())->get(route('company.dashboard'))->assertOk()->assertSee('Dashibodi')->assertSee('Matawi');

        $this->app['auth']->forgetGuards();
        $this->withSession(['locale' => 'sw'])->get('/login')->assertOk()->assertSee('Ingia')->assertSee('Nenosiri');
    }

    public function test_unsupported_locale_is_rejected(): void
    {
        $this->post(route('locale.update'), ['locale' => 'fr'])->assertSessionHasErrors('locale');
    }

    public function test_branch_layout_shows_only_permitted_modules(): void
    {
        $company = $this->createCompany();
        $cashier = $this->addMember($company, SystemRole::Cashier, [$this->headOffice($company)]);

        $this->actingAs($cashier)->get(route('branch.dashboard'))
            ->assertOk()
            ->assertSee('Point of sale')
            ->assertDontSee('Expenses')
            ->assertDontSee('Reports');
    }
}
