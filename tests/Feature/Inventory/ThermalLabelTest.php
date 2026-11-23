<?php

namespace Tests\Feature\Inventory;

use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Support\Barcode\BarcodeSvg;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesInventory;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Thermal sticker labels 70 x 37 mm (ROMESON ZJ80UEB, 74 mm gap media):
 * the print page IS the sticker, one label per page, no A4, exact-size barcode.
 */
class ThermalLabelTest extends TestCase
{
    use CreatesInventory, CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    private Company $company;

    private User $owner;

    private Product $active;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany();
        $this->owner = $this->createOwner($this->company);
        $this->actingAsTenant($this->owner, $this->company);
        $this->active = $this->makeProduct($this->company, $this->owner, ['name' => 'ACTIVE MAN', 'barcode' => '6291100171255', 'selling_price' => '7000']);
    }

    private function labels(array $query = [])
    {
        return $this->actingAs($this->owner)->get(route('catalogue.products.labels', [$this->active] + $query))->assertOk();
    }

    public function test_thermal_70x37_prints_one_sticker_per_page_without_a4(): void
    {
        $html = $this->labels(['layout' => 'thermal-70x37', 'copies' => 3])->getContent();

        $this->assertStringContainsString('@page { size: 70mm 37mm; margin: 0; }', $html);
        $this->assertStringContainsString('break-after: page; page-break-after: always;', $html);
        $this->assertStringContainsString('.label:last-of-type { break-after: auto; page-break-after: auto; }', $html);   // no blank trailing sticker
        $this->assertStringContainsString('padding: 1.5mm 2mm;', $html);                                                  // 66 x 34 mm safe area
        $this->assertStringContainsString('justify-content: center', $html);
        $this->assertSame(3, substr_count($html, 'data-label>'));
        $this->assertStringNotContainsString('A4', str_replace(['A4 sheet', 'Karatasi A4'], '', $html));
        $this->assertStringNotContainsString('build/assets/app', $html);                                                // no app CSS deciding the size
        $this->assertStringNotContainsString('@page { margin: 8mm', $html);

        // content
        $this->assertStringContainsString('<p class="name" data-label-name>ACTIVE MAN</p>', $html);
        $this->assertStringContainsString('6291100171255', $html);
        $this->assertStringContainsString('TZS 7,000.00', $html);
        $this->assertStringContainsString('/ Piece', $html);
        $this->assertStringContainsString('width="58.500mm" height="12.00mm"', $html);                                   // exact-size barcode
        $this->assertStringContainsString('font-size: 12pt', $html);                                                     // short name: large
    }

    public function test_rotated_fallback_37x70(): void
    {
        $html = $this->labels(['layout' => 'thermal-37x70'])->getContent();

        $this->assertStringContainsString('@page { size: 37mm 70mm; margin: 0; }', $html);
        $this->assertStringContainsString('class="sheet rotated"', $html);
        $this->assertStringContainsString('transform: rotate(90deg)', $html);
        $this->assertSame(1, substr_count($html, 'data-label>'));                                                        // default: 1 copy
    }

    public function test_long_names_wrap_instead_of_being_cut(): void
    {
        $long = $this->makeProduct($this->company, $this->owner, ['name' => 'ACTIVE MAN DEODORANT BODY SPRAY FRESH OCEAN 150ML LONG LASTING PROTECTION', 'barcode' => '6291100171262']);
        $html = $this->actingAs($this->owner)->get(route('catalogue.products.labels', [$long, 'layout' => 'thermal-70x37']))->assertOk()->getContent();

        $this->assertStringContainsString('ACTIVE MAN DEODORANT BODY SPRAY FRESH OCEAN 150ML LONG LASTING PROTECTION', $html);
        $this->assertStringContainsString('font-size: 7.5pt', $html);
        $this->assertStringContainsString('-webkit-line-clamp: 3', $html);
        $this->assertStringNotContainsString('truncate', $html);
    }

    public function test_kiswahili_unit_and_print_help(): void
    {
        $this->owner->forceFill(['locale' => 'sw'])->save();
        $this->actingAs($this->owner->fresh())->get(route('catalogue.products.labels', [$this->active, 'layout' => 'thermal-70x37']))->assertOk()
            ->assertSee('/ Kipande')->assertSee('Chapisha')->assertSee('Pambizo')->assertSee('Hakuna')->assertSee('100%');
    }

    public function test_layout_is_remembered_validated_and_a4_still_works(): void
    {
        $this->labels(['layout' => 'thermal-37x70']);
        $this->labels()->assertSee('@page { size: 37mm 70mm; margin: 0; }', false);                                   // remembered

        $this->actingAs($this->owner)->get(route('catalogue.products.labels', [$this->active, 'layout' => 'a3']))->assertSessionHasErrors('layout');

        $html = $this->labels(['layout' => 'a4', 'copies' => 6])->getContent();
        $this->assertStringContainsString('grid grid-cols-3', $html);
        $this->assertStringContainsString('Thermal 70×37 mm', $html);                                                    // switchable from the A4 page
        $this->labels(['layout' => 'thermal-70x37', 'autoprint' => 1])->assertSee('window.print()', false);
    }

    public function test_barcode_physical_size_and_quiet_zones(): void
    {
        $module = 0.5;
        $svg = BarcodeSvg::svgMm('6291100171255', 12, $module, 64);
        $this->assertSame(0.5, $module);                                                                                 // 95 + 2x11 modules = 58.5 mm
        $this->assertStringContainsString('viewBox="0 0 117 1"', $svg);
        $this->assertStringStartsWith('101', BarcodeSvg::modules('6291100171255'));

        // a long Code 128 value is narrowed to fit the label, not cropped
        $module = 0.5;
        $svg = BarcodeSvg::svgMm('INTERNAL-SHELF-CODE-000123', 12, $module, 64);
        $this->assertLessThan(0.5, $module);
        preg_match('/width="([\d.]+)mm"/', $svg, $m);
        $this->assertLessThanOrEqual(64.0, (float) $m[1]);
    }
}
