<?php

namespace Tests\Feature\Pos;

use App\Enums\BarcodeType;
use App\Enums\LocationType;
use App\Enums\SystemRole;
use App\Livewire\Pos\Terminal;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalogue\BarcodeService;
use App\Services\Catalogue\PriceService;
use App\Services\Pos\PosCatalog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Attributes\Renderless;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\Concerns\CreatesInventory;
use Tests\Concerns\CreatesPos;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * POS product search: find and sell products by name / SKU / barcode
 * without scanning, alongside the unchanged barcode scanner.
 */
class PosProductSearchTest extends TestCase
{
    use CreatesInventory, CreatesPos, CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    /** @return array{0: Company, 1: User, 2: User, 3: Product, 4: Product} */
    private function shop(): array
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $soap = $this->makeProduct($company, $owner, ['name' => 'FAMILY MEDICATED SOAP', 'selling_price' => '2500', 'barcode' => '6203013430060']);
        $snickers = $this->makeProduct($company, $owner, ['name' => 'SNICKERS 50G', 'selling_price' => '1800']);  // no barcode
        $this->stock($company, $owner, $soap, '6');
        $this->stock($company, $owner, $snickers, '3');
        $device = $this->device($this->headOffice($company));
        $cashier = $this->addMember($company, SystemRole::Cashier, [$this->headOffice($company)]);
        $this->actingAsTenant($cashier, $company);
        session()->put('pos.device.'.$this->headOffice($company)->id, $device->uuid);

        return [$company, $owner, $cashier, $soap, $snickers];
    }

    private function search(Company $company, string $term): array
    {
        $branch = $this->headOffice($company);

        return app(PosCatalog::class)->searchSellable($company, $branch, $this->location($branch, LocationType::SalesFloor), $term);
    }

    private function names(array $rows): array
    {
        return array_column($rows, 'name');
    }

    public function test_search_by_full_partial_and_case_insensitive_name(): void
    {
        [$company] = $this->shop();

        $this->assertSame(['FAMILY MEDICATED SOAP'], $this->names($this->search($company, 'FAMILY MEDICATED SOAP')));
        $this->assertSame(['FAMILY MEDICATED SOAP'], $this->names($this->search($company, 'medicated')));
        $this->assertSame(['SNICKERS 50G'], $this->names($this->search($company, 'snic')));
        $this->assertSame(['SNICKERS 50G'], $this->names($this->search($company, '  SnIc  ')));        // whitespace + case
        $this->assertSame(['FAMILY MEDICATED SOAP'], $this->names($this->search($company, 'soap family')));  // every word, any order
        $this->assertSame([], $this->search($company, 's'));                                            // too short
        $this->assertSame([], $this->search($company, '%_'));                                           // LIKE wildcards are literal
    }

    public function test_search_by_sku_and_barcode_with_exact_codes_ranked_first(): void
    {
        [$company, , , $soap, $snickers] = $this->shop();

        $bySku = $this->search($company, strtolower($snickers->sku));
        $this->assertSame('SNICKERS 50G', $bySku[0]['name']);
        $this->assertTrue($bySku[0]['exact']);
        $this->assertSame(['SNICKERS 50G'], $this->names($this->search($company, substr($snickers->sku, -5))));

        $byBarcode = $this->search($company, '6203013430060');
        $this->assertSame(['FAMILY MEDICATED SOAP'], $this->names($byBarcode));
        $this->assertTrue($byBarcode[0]['exact']);
        $this->assertSame('6203013430060', $byBarcode[0]['barcode']);
        $this->assertFalse($this->search($company, '62030134')[0]['exact']);                          // barcode prefix
    }

    public function test_products_without_barcodes_show_price_unit_stock_and_status(): void
    {
        [$company] = $this->shop();

        $row = $this->search($company, 'snickers')[0];

        $this->assertNull($row['barcode']);
        $this->assertSame('1,800.00', $row['price']);
        $this->assertSame('Piece', $row['unit']);
        $this->assertSame('3', $row['available']);
        $this->assertSame('in_stock', $row['status']);
        $this->assertNotEmpty($row['uuid']);
        $this->assertArrayNotHasKey('id', $row);                                                       // no internal ids to the browser
    }

    public function test_results_are_ranked_and_limited(): void
    {
        [$company, $owner] = $this->shop();
        $this->actingAsTenant($owner, $company);
        foreach (range(1, 14) as $i) {
            $this->makeProduct($company, $owner, ['name' => "MAJI SAFI {$i}L"]);
        }
        $this->makeProduct($company, $owner, ['name' => 'KILIMANJARO MAJI 1L']);

        $rows = $this->search($company, 'maji');
        $this->assertCount(12, $rows);
        $this->assertStringStartsWith('MAJI SAFI', $rows[0]['name']);                                  // name prefix before "contains"
    }

    public function test_selecting_a_result_adds_quantity_one_and_repeat_increments(): void
    {
        [, , , $soap] = $this->shop();

        $pos = Livewire::test(Terminal::class)->call('addProduct', $soap->uuid)->assertHasNoErrors();
        $line = array_values($pos->get('cart'))[0];
        $this->assertSame([$soap->id, '1', $this->baseUnitOf($soap)->id], [$line['product_id'], $line['quantity'], $line['product_unit_id']]);
        $pos->assertSee('2,500.00');

        $pos->call('addProduct', $soap->uuid);
        $this->assertCount(1, $pos->get('cart'));
        $this->assertSame('2', array_values($pos->get('cart'))[0]['quantity']);
        $pos->assertSee('5,000.00');
    }

    public function test_branch_price_and_default_selling_unit_are_applied(): void
    {
        [$company, $owner, , , $snickers] = $this->shop();
        $this->actingAsTenant($owner, $company);
        app(PriceService::class)->setPrice($snickers, $this->baseUnitOf($snickers), '2000', $this->headOffice($company), $owner);

        $this->assertSame('2,000.00', $this->search($company, 'snickers')[0]['price']);

        $pos = Livewire::test(Terminal::class)->call('addProduct', $snickers->uuid);
        $pos->assertSee('2,000.00');
        $this->assertSame($this->baseUnitOf($snickers)->id, array_values($pos->get('cart'))[0]['product_unit_id']);
    }

    public function test_out_of_stock_and_receiving_area_stock_cannot_be_sold(): void
    {
        [$company, $owner] = $this->shop();
        $this->actingAsTenant($owner, $company);
        $rice = $this->makeProduct($company, $owner, ['name' => 'RICE KILOMBERO']);
        $this->stock($company, $owner, $rice, '50', type: LocationType::ReceivingArea);   // not a selling location

        $row = $this->search($company, 'kilombero')[0];
        $this->assertSame(['out_of_stock', '0'], [$row['status'], $row['available']]);

        $pos = Livewire::test(Terminal::class);
        $this->assertFalse($pos->call('addProduct', $rice->uuid)->effects['returns'][0] ?? true);
        $pos->assertHasErrors('scan')->assertSet('cart', [])->assertSee('out of stock at Sales Floor');
    }

    public function test_inactive_and_other_company_products_are_not_found_or_sellable(): void
    {
        [$company, , $cashier, $soap] = $this->shop();
        app(TenantContext::class)->clear();
        $other = $this->createCompany();
        $otherOwner = $this->createOwner($other);
        $this->actingAsTenant($otherOwner, $other);
        $foreign = $this->makeProduct($other, $otherOwner, ['name' => 'FAMILY MEDICATED SOAP FOREIGN', 'barcode' => '6009999999993']);
        $soap->forceFill(['is_active' => false])->save();

        $this->actingAsTenant($cashier, $company);

        $this->assertSame([], $this->search($company, 'medicated'));
        $this->assertSame([], $this->search($company, '6009999999993'));

        Livewire::test(Terminal::class)->call('addProduct', $foreign->uuid)->assertHasErrors('scan')->assertSet('cart', [])
            ->call('addProduct', $soap->uuid)->assertHasErrors('scan')->assertSet('cart', [])
            ->call('addProduct', 'not-a-uuid')->assertHasErrors('scan')->assertSet('cart', []);
    }

    public function test_search_endpoint_is_renderless_and_scoped(): void
    {
        [, , , $soap] = $this->shop();

        $this->assertNotEmpty((new ReflectionMethod(Terminal::class, 'searchProducts'))->getAttributes(Renderless::class));

        $pos = Livewire::test(Terminal::class)->call('searchProducts', 'medicated');
        $returned = $pos->effects['returns'][0];
        $this->assertSame('medicated', $returned['term']);
        $this->assertSame($soap->uuid, $returned['results'][0]['uuid']);
    }

    public function test_scanner_and_search_work_together_including_packaging_barcodes(): void
    {
        [$company, $owner, $cashier, $soap] = $this->shop();
        $this->actingAsTenant($owner, $company);
        $carton = $this->packaging($soap, 'ctn', '3');
        app(BarcodeService::class)->add($soap, '6203013430077', BarcodeType::Packaging, $carton);
        $this->actingAsTenant($cashier, $company);

        // the exact packaging code is flagged so the client sends it through scan()
        $this->assertTrue($this->search($company, '6203013430077')[0]['exact']);

        $pos = Livewire::test(Terminal::class)
            ->call('scan', '6203013430060')
            ->call('addProduct', $soap->uuid)
            ->call('scan', '6203013430077');
        $cart = collect($pos->get('cart'));
        $this->assertSame('2', $cart->firstWhere('product_unit_id', $this->baseUnitOf($soap)->id)['quantity']);
        $this->assertSame('1', $cart->firstWhere('product_unit_id', $carton->id)['quantity']);

        $this->actingAs($cashier)->get(route('pos'))->assertOk()
            ->assertSee('data-barcode-scan', false)
            ->assertSee('x-on:keydown.enter.prevent="submit()"', false)
            ->assertSee('x-data="posSearch()"', false)
            ->assertSee('role="combobox"', false)
            ->assertSee('x-on:keydown.arrow-down.prevent="move(1)"', false)
            ->assertSee('x-on:keydown.arrow-up.prevent="move(-1)"', false)
            ->assertSee('x-on:keydown.enter.prevent="enter()"', false)
            ->assertSee('x-on:keydown.escape.window="close()"', false)
            ->assertSee('max-h-[min(24rem,45dvh)]', false)       // results never taller than ~half the (keyboard-reduced) screen
            ->assertDontSee('shift', false);

        $scanner = file_get_contents(resource_path('js/pos/scanner.js'));
        $this->assertStringContainsString("addEventListener('pos-scan'", $scanner);
        $search = file_get_contents(resource_path('js/pos/search.js'));
        $this->assertStringContainsString('seq !== this.seq', $search);                // stale responses dropped
    }
}
