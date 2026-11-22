<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesInventory;
use Tests\Concerns\CreatesPos;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Markup-level regression tests for the responsive UI (Phase 3 UI/UX).
 * Real layout behaviour (widths 320-1920 px, scanner keystrokes, print
 * output) is verified in a browser; these tests make sure the hooks that
 * behaviour depends on are not accidentally removed.
 */
class ResponsiveLayoutTest extends TestCase
{
    use CreatesInventory, CreatesPos, CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    public function test_mobile_navigation_is_an_accessible_off_canvas_drawer(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);

        $this->actingAs($owner)->get(route('company.dashboard'))->assertOk()
            ->assertSee('name="viewport"', false)
            ->assertSee('aria-controls="app-sidebar"', false)
            ->assertSee(':aria-expanded="sidebarOpen.toString()"', false)
            ->assertSee('id="app-sidebar"', false)
            // closed drawer is removed from tab order on phones; links close it
            ->assertSee('$el.inert = ! desktop && ! sidebarOpen', false)
            ->assertSee("closest('a[href]')", false)
            ->assertSee('x-on:keydown.escape.window', false)
            ->assertSee('min-w-0 lg:pl-72', false)
            ->assertSee('id="main"', false);
    }

    public function test_data_tables_stack_into_cards_on_phones(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);

        foreach (['catalogue.categories.index', 'company.staff.index', 'company.pos-devices.index', 'inventory.stock'] as $route) {
            $this->actingAs($owner)->get(route($route))->assertOk()
                ->assertSee('data-stack', false)
                ->assertSee('relative overflow-x-auto', false);   // keeps sr-only header text inside the scroller
        }

        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('table[data-stack] > tbody > tr > td::before', $css);
        $this->assertStringContainsString('content: attr(data-label)', $css);
        $this->assertStringContainsString(':focus-visible', $css);
        $this->assertStringContainsString("import './responsive-tables';", file_get_contents(resource_path('js/app.js')));
    }

    public function test_pos_keeps_scanner_hooks_and_has_a_mobile_checkout_bar(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $device = $this->device($this->headOffice($company));
        $cashier = $this->addMember($company, SystemRole::Cashier, [$this->headOffice($company)]);
        $this->actingAsTenant($cashier, $company);
        session()->put('pos.device.'.$this->headOffice($company)->id, $device->uuid);

        $this->actingAs($cashier)->get(route('pos'))->assertOk()
            ->assertSee('data-barcode-scan', false)
            ->assertSee('x-on:keydown.enter.prevent="submit()"', false)
            ->assertSee('wire:ignore', false)                         // Livewire never resets a half-typed scan
            ->assertSee('data-pos-mobile-bar', false)
            ->assertSee('env(safe-area-inset-bottom)', false)
            ->assertSee('lg:grid-cols-[minmax(0,1fr)_380px]', false)
            ->assertDontSee('shift', false);
    }

    public function test_receipt_prints_light_and_wraps_long_text(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $soap = $this->makeProduct($company, $owner, ['name' => 'FAMILY MEDICATED SOAP', 'selling_price' => '2500']);
        $this->stock($company, $owner, $soap, '5');
        $device = $this->device($this->headOffice($company), 'Till 1', 80);
        $sale = $this->sell($company, $owner, $device,
            [['product_id' => $soap->id, 'product_unit_id' => $this->baseUnitOf($soap)->id, 'quantity' => '1']],
            [['payment_method_id' => $this->method($company)->id, 'amount' => '2500']]);

        foreach ([58, 80] as $width) {
            $this->actingAs($owner)->get(route('sales.receipt', [$sale, 'width' => $width]))->assertOk()
                ->assertSee("size: {$width}mm auto", false)
                ->assertSee('color-scheme: light', false)
                ->assertSee('overflow-wrap: anywhere', false);
        }
    }
}
