<?php

namespace Tests\Feature\Inventory;

use App\Enums\LocationType;
use App\Enums\SystemRole;
use App\Livewire\Catalogue\BarcodeLookup;
use App\Livewire\Catalogue\ProductForm;
use App\Livewire\Pos\Terminal;
use App\Livewire\Purchasing\PurchaseOrderForm;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductPrice;
use App\Models\ProductUnit;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\Pos\PosCatalog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesInventory;
use Tests\Concerns\CreatesPos;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * One-step product registration: name + category + unit + selling price
 * (+ optional barcode) -> Save -> ready for POS lookup. Stock still only
 * comes from goods receipts.
 */
class ProductRegistrationTest extends TestCase
{
    use CreatesInventory, CreatesPos, CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    /** @return array{0: Company, 1: User} */
    private function owner(): array
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);

        return [$company, $owner];
    }

    private function form(Company $company, string $name = 'TEST SOAP', string $price = '3000')
    {
        return Livewire::test(ProductForm::class)
            ->set('name', $name)
            ->set('category', $this->defaultCategory($company)->uuid)
            ->set('sellingPrice', $price);
    }

    private function product(string $name): Product
    {
        return Product::withoutTenancy()->where('name', $name)->firstOrFail();
    }

    public function test_minimal_product_gets_base_unit_sku_and_price_history_but_no_stock(): void
    {
        [$company] = $this->owner();

        $this->form($company, 'TEST BISCUITS', '1,500')->call('save')->assertHasNoErrors()->assertRedirect();

        $p = $this->product('TEST BISCUITS');
        $this->assertMatchesRegularExpression('/^SKU-[0-9A-F]{8}$/', $p->sku);          // auto-generated
        $this->assertSame('standard', $p->product_type->value);
        $this->assertSame('pc', $p->baseUnit->code);
        $this->assertSame('1500.00', $p->selling_price);
        $base = ProductUnit::withoutTenancy()->where('product_id', $p->id)->sole();
        $this->assertTrue($base->is_base && $base->can_sell && $base->can_purchase);
        $this->assertSame('1.000000', $base->factor);

        // the initial price is a price-history record (company-wide, base unit)
        $price = ProductPrice::withoutTenancy()->where('product_id', $p->id)->sole();
        $this->assertSame(['1500.00', null, $base->id], [$price->price, $price->branch_id, $price->product_unit_id]);
        $this->assertSame('Initial price', $price->notes);

        // no barcode, no stock, no cost, no GRN
        $this->assertSame(0, ProductBarcode::withoutTenancy()->where('product_id', $p->id)->count());
        $this->assertSame(0, StockMovement::withoutTenancy()->where('product_id', $p->id)->count());
        $this->assertSame(0, StockBalance::withoutTenancy()->where('product_id', $p->id)->count());
        $this->assertSame(0, GoodsReceipt::withoutTenancy()->count());
        $this->assertSame('0.0000', $p->purchase_cost);
    }

    public function test_product_with_barcode_is_immediately_found_by_barcode_lookup_and_pos(): void
    {
        [$company, $owner] = $this->owner();
        $this->form($company)->set('barcode', ' 6201234567893 ')->call('save')->assertHasNoErrors();
        $p = $this->product('TEST SOAP');

        $barcode = ProductBarcode::withoutTenancy()->where('product_id', $p->id)->sole();
        $this->assertSame(['6201234567893', 'manufacturer', true], [$barcode->barcode, $barcode->type->value, $barcode->is_primary]);

        Livewire::test(BarcodeLookup::class)->set('code', '6201234567893')->call('lookup')
            ->assertSet('result.product', 'TEST SOAP')->assertSet('result.price', '3000.00');

        // POS: found by barcode/name/SKU with the right price; sellable once stock is received
        $branch = $this->headOffice($company);
        $floor = $this->location($branch, LocationType::SalesFloor);
        $rows = app(PosCatalog::class)->searchSellable($company, $branch, $floor, 'test soap');
        $this->assertSame(['TEST SOAP', '3,000.00', 'out_of_stock'], [$rows[0]['name'], $rows[0]['price'], $rows[0]['status']]);
        $this->assertSame('TEST SOAP', app(PosCatalog::class)->searchSellable($company, $branch, $floor, $p->sku)[0]['name']);

        $this->actingAsTenant($owner, $company);
        $this->stock($company, $owner, $p, '5');
        $device = $this->device($branch);
        session()->put('pos.device.'.$branch->id, $device->uuid);
        $pos = Livewire::test(Terminal::class)->call('scan', '6201234567893')->assertHasNoErrors();
        $pos->assertSee('3,000.00');
    }

    public function test_product_without_barcode_is_sellable_after_stock_is_received(): void
    {
        [$company, $owner] = $this->owner();
        $this->form($company, 'TEST BISCUITS', '1500')->call('save')->assertHasNoErrors();
        $p = $this->product('TEST BISCUITS');
        $this->actingAsTenant($owner, $company);
        $this->stock($company, $owner, $p, '10');      // received into the sales floor

        $branch = $this->headOffice($company);
        $rows = app(PosCatalog::class)->searchSellable($company, $branch, $this->location($branch, LocationType::SalesFloor), 'biscuits');
        $this->assertSame([null, 'in_stock', '1,500.00'], [$rows[0]['barcode'], $rows[0]['status'], $rows[0]['price']]);

        $sale = $this->sell($company, $owner, $this->device($branch), [['product_id' => $p->id, 'product_unit_id' => $this->baseUnitOf($p)->id, 'quantity' => '2']],
            [['payment_method_id' => $this->method($company)->id, 'amount' => '3000']]);
        $this->assertSame('3000.00', $sale->total);
    }

    public function test_internal_barcode_is_only_generated_on_request(): void
    {
        [$company] = $this->owner();
        $this->form($company, 'NO CODE')->call('save');
        $this->form($company, 'WANTS CODE')->set('generateBarcode', true)->call('save')->assertHasNoErrors();

        $this->assertSame(0, ProductBarcode::withoutTenancy()->where('product_id', $this->product('NO CODE')->id)->count());
        $this->assertSame('internal', ProductBarcode::withoutTenancy()->where('product_id', $this->product('WANTS CODE')->id)->sole()->type->value);
    }

    public function test_duplicate_barcodes_are_rejected_without_losing_input_and_isolated_per_company(): void
    {
        [$company] = $this->owner();
        $this->form($company)->set('barcode', '6201234567893')->call('save');

        $this->form($company, 'SECOND SOAP')->set('barcode', '6201234567893')->set('buyingPrice', '2000')->call('save')
            ->assertHasErrors('barcode')->assertSet('name', 'SECOND SOAP')->assertSet('buyingPrice', '2000')->assertNoRedirect();
        $this->assertSame(0, Product::withoutTenancy()->where('name', 'SECOND SOAP')->count());        // whole registration rolled back
        $this->assertSame(0, ProductPrice::withoutTenancy()->whereIn('product_id', Product::withoutTenancy()->where('name', 'SECOND SOAP')->select('id'))->count());

        // another company may use the same barcode
        app(TenantContext::class)->clear();
        $other = $this->createCompany();
        $this->actingAsTenant($this->createOwner($other), $other);
        $this->form($other)->set('barcode', '6201234567893')->call('save')->assertHasNoErrors();
        $this->assertSame(1, ProductBarcode::forCompany($other)->where('barcode', '6201234567893')->count());
    }

    public function test_selling_price_is_required_and_positive_and_input_is_validated(): void
    {
        [$company] = $this->owner();

        $this->form($company, 'NO PRICE', '')->call('save')->assertHasErrors(['sellingPrice' => 'required']);
        $this->form($company, 'ZERO PRICE', '0')->call('save')->assertHasErrors('sellingPrice');
        $this->form($company, 'BAD PRICE', '12.345')->call('save')->assertHasErrors('sellingPrice');
        Livewire::test(ProductForm::class)->set('sellingPrice', '100')->call('save')->assertHasErrors(['name', 'category']);
        $this->assertSame(0, Product::withoutTenancy()->count());
    }

    public function test_reference_buying_price_is_stored_separately_and_never_used_as_cost(): void
    {
        [$company, $owner] = $this->owner();
        $this->form($company)->set('buyingPrice', '2,100')->call('save')->assertHasNoErrors();
        $p = $this->product('TEST SOAP');

        $this->assertSame('2100.0000', $p->reference_buying_price);
        $this->assertSame('0.0000', $p->purchase_cost);                                  // actual cost untouched
        $this->assertSame(0, StockMovement::withoutTenancy()->where('product_id', $p->id)->count());

        // only a default for new purchase-order lines
        $po = Livewire::test(PurchaseOrderForm::class)->call('pickProduct', $p->id);
        $this->assertSame('2100', $po->get('lines')[0]['unit_cost']);

        // no profit estimate from a reference price
        $this->actingAs($owner)->get(route('catalogue.products.show', $p))->assertOk()
            ->assertSee('Reference buying price')->assertSee('No actual cost yet');

        // optional
        $this->form($company, 'NO COST')->call('save')->assertHasNoErrors();
        $this->assertNull($this->product('NO COST')->reference_buying_price);
    }

    public function test_advanced_packaging_units_extra_barcodes_and_weighed_products(): void
    {
        [$company] = $this->owner();
        $ctn = Unit::withoutTenancy()->where('code', 'ctn')->first();
        $g = Unit::withoutTenancy()->where('code', 'g')->first();

        $this->form($company, 'JUICE 500ML', '1500')
            ->set('extraBarcodes', ['6201234567909'])
            ->set('packaging', [['unit' => $ctn->uuid, 'factor' => '24', 'barcode' => '16201234567890', 'price' => '33000', 'purchase' => true, 'sell' => true]])
            ->call('save')->assertHasNoErrors();
        $juice = $this->product('JUICE 500ML');
        $carton = ProductUnit::withoutTenancy()->where('product_id', $juice->id)->where('unit_id', $ctn->id)->sole();
        $this->assertSame('24.000000', $carton->factor);
        $this->assertSame(['6201234567909', '16201234567890'], ProductBarcode::withoutTenancy()->where('product_id', $juice->id)->orderBy('id')->pluck('barcode')->all());
        $this->assertSame('packaging', ProductBarcode::withoutTenancy()->where('barcode', '16201234567890')->sole()->type->value);
        $this->assertSame('33000.00', ProductPrice::withoutTenancy()->where('product_unit_id', $carton->id)->sole()->price);

        // weighed: type switch moves the base unit to kg; grams convert automatically
        $form = $this->form($company, 'SUGAR LOOSE', '3200')->set('productType', 'weighed');
        $this->assertSame(Unit::withoutTenancy()->where('code', 'kg')->value('uuid'), $form->get('baseUnit'));
        $form->set('packaging', [['unit' => $g->uuid, 'factor' => '', 'barcode' => '', 'price' => '', 'purchase' => false, 'sell' => true]])
            ->call('save')->assertHasNoErrors();
        $this->assertSame('0.001000', ProductUnit::withoutTenancy()->where('product_id', $this->product('SUGAR LOOSE')->id)->where('unit_id', $g->id)->sole()->factor);

        // a wrong physical factor is refused (no silent incorrect conversion) and nothing is saved
        $this->form($company, 'BAD SUGAR', '3200')->set('productType', 'weighed')
            ->set('packaging', [['unit' => $g->uuid, 'factor' => '5', 'barcode' => '', 'price' => '', 'purchase' => true, 'sell' => true]])
            ->call('save')->assertHasErrors('packaging.0')->assertSet('showAdvanced', true);
        $this->assertSame(0, Product::withoutTenancy()->where('name', 'BAD SUGAR')->count());
    }

    public function test_save_and_add_another_keeps_grouping_and_resets_the_rest(): void
    {
        [$company] = $this->owner();
        $category = $this->defaultCategory($company);

        $form = $this->form($company, 'FIRST')->set('barcode', '6201234567893')->set('buyingPrice', '900')
            ->call('save', 'another')->assertHasNoErrors()->assertNoRedirect()->assertDispatched('product-saved');

        $form->assertSet('name', '')->assertSet('barcode', '')->assertSet('sellingPrice', '')->assertSet('buyingPrice', '')
            ->assertSet('category', $category->uuid)->assertSee('Product "FIRST" saved');

        $form->set('name', 'SECOND')->set('sellingPrice', '1000')->call('save', 'another')->assertHasNoErrors();
        $this->assertSame(2, Product::withoutTenancy()->count());
    }

    public function test_double_submission_creates_one_product(): void
    {
        [$company] = $this->owner();
        $form = $this->form($company, 'DOUBLE CLICK');

        $form->call('save')->assertRedirect();
        $first = $this->product('DOUBLE CLICK');
        // the same submission (same idempotency key) arriving again
        $form->call('save')->assertRedirect(route('catalogue.products.show', $first));

        $this->assertSame(1, Product::withoutTenancy()->where('name', 'DOUBLE CLICK')->count());
        $this->assertSame(1, ProductPrice::withoutTenancy()->where('product_id', $first->id)->count());
        $this->assertTrue(Product::withoutTenancy()->count() === 1);
    }

    public function test_edit_changes_price_through_history_and_protects_structure(): void
    {
        [$company, $owner] = $this->owner();
        $this->form($company, 'SOLD SOAP', '2500')->call('save');
        $p = $this->product('SOLD SOAP');
        $this->actingAsTenant($owner, $company);
        $this->stock($company, $owner, $p, '10');
        $branch = $this->headOffice($company);
        $sale = $this->sell($company, $owner, $this->device($branch), [['product_id' => $p->id, 'product_unit_id' => $this->baseUnitOf($p)->id, 'quantity' => '1']],
            [['payment_method_id' => $this->method($company)->id, 'amount' => '2500']]);
        $movements = StockMovement::withoutTenancy()->where('product_id', $p->id)->count();

        Livewire::test(ProductForm::class, ['product' => $p])
            ->assertSet('sellingPrice', '2500')
            ->set('name', 'SOLD SOAP 100G')->set('sellingPrice', '2700')->set('buyingPrice', '1800')
            ->call('save')->assertHasNoErrors();

        $p->refresh();
        $this->assertSame(['SOLD SOAP 100G', '2700.00', '1800.0000'], [$p->name, $p->selling_price, $p->reference_buying_price]);
        $this->assertSame(['2500.00', '2700.00'], ProductPrice::withoutTenancy()->where('product_id', $p->id)->orderBy('id')->pluck('price')->all());  // history kept
        $this->assertSame('2500.00', $sale->items()->sole()->unit_price);                                                                   // sale snapshot unchanged
        $this->assertSame($movements, StockMovement::withoutTenancy()->where('product_id', $p->id)->count());

        // unchanged price -> no new history row
        Livewire::test(ProductForm::class, ['product' => $p])->call('save')->assertHasNoErrors();
        $this->assertSame(2, ProductPrice::withoutTenancy()->where('product_id', $p->id)->count());

        // base unit / type locked once stock exists
        Livewire::test(ProductForm::class, ['product' => $p])->set('productType', 'weighed')->call('save')->assertHasErrors('baseUnit');
        $this->assertSame('pc', $p->fresh()->baseUnit->code);
        $this->actingAs($owner)->get(route('catalogue.products.edit', $p))->assertOk()->assertSee('Locked: this product already has stock or purchase records.');
    }

    public function test_edit_can_add_a_first_barcode_but_not_replace_existing_ones(): void
    {
        [$company, $owner] = $this->owner();
        $this->form($company, 'LATE BARCODE')->call('save');
        $p = $this->product('LATE BARCODE');

        Livewire::test(ProductForm::class, ['product' => $p])->set('barcode', '6201234567893')->call('save')->assertHasNoErrors();
        $this->assertSame(['6201234567893'], ProductBarcode::withoutTenancy()->where('product_id', $p->id)->pluck('barcode')->all());

        Livewire::test(ProductForm::class, ['product' => $p])->set('barcode', '6201234567909')->call('save')->assertHasNoErrors();
        $this->assertSame(['6201234567893'], ProductBarcode::withoutTenancy()->where('product_id', $p->id)->pluck('barcode')->all());
        $this->actingAs($owner)->get(route('catalogue.products.edit', $p))->assertSee('data-existing-barcodes', false);
    }

    public function test_quick_category_and_brand_respect_permissions_and_uniqueness(): void
    {
        [$company] = $this->owner();

        Livewire::test(ProductForm::class)->set('name', 'KEEP ME')
            ->set('newCategoryName', 'Cleaning Supplies')->call('createCategory')->assertHasNoErrors()
            ->assertSet('category', Category::withoutTenancy()->where('name', 'Cleaning Supplies')->value('uuid'))
            ->assertSet('name', 'KEEP ME')                                                // entered data kept
            ->set('newCategoryName', 'cleaning supplies')->call('createCategory')->assertHasErrors('newCategoryName')
            ->set('newBrandName', 'Dettol')->call('createBrand')->assertHasNoErrors()
            ->assertSet('brand', Brand::withoutTenancy()->where('name', 'Dettol')->value('uuid'));

        // a store keeper may create products but not categories/brands or prices
        $keeper = $this->addMember($company, SystemRole::StoreKeeper, [$this->headOffice($company)]);
        $this->actingAsTenant($keeper, $company);
        Livewire::test(ProductForm::class)->set('newCategoryName', 'Sneaky')->call('createCategory')->assertForbidden();
        Livewire::test(ProductForm::class)->set('newBrandName', 'Sneaky')->call('createBrand')->assertForbidden();
        $this->assertSame(0, Category::withoutTenancy()->where('name', 'Sneaky')->count());

        // ...and registers products without a price (POS refuses to sell them until priced)
        Livewire::test(ProductForm::class)->set('name', 'KEEPER ITEM')->set('category', $this->defaultCategory($company)->uuid)
            ->set('sellingPrice', '999')->call('save')->assertHasNoErrors();
        $item = $this->product('KEEPER ITEM');
        $this->assertSame('0.00', $item->selling_price);
        $this->assertSame(0, ProductPrice::withoutTenancy()->where('product_id', $item->id)->count());
        $this->expectExceptionMessage('has no selling price yet');
        app(PosCatalog::class)->item($item, $this->baseUnitOf($item), $this->headOffice($company));
    }

    public function test_form_renders_responsive_accessible_and_translated(): void
    {
        [$company, $owner] = $this->owner();

        $this->actingAs($owner)->get(route('catalogue.products.create'))->assertOk()
            ->assertSee('Add product')->assertSee('Advanced settings')->assertSee('Save &amp; add another', false)
            ->assertSee('data-advanced', false)->assertSee('aria-controls="advanced-panel"', false)
            ->assertSee('x-on:keydown.enter.prevent="document.getElementById(\'sku\')?.focus()"', false)   // scanner Enter never submits
            ->assertSee('md:grid-cols-2', false)->assertSee('env(safe-area-inset-bottom)', false)
            ->assertSee('wire:loading.attr="disabled"', false)
            ->assertSee('for="price"', false)->assertSee('for="barcode"', false)->assertSee('for="category"', false)
            ->assertDontSee('shift', false);

        $owner->forceFill(['locale' => 'sw'])->save();
        $this->actingAs($owner->fresh())->get(route('catalogue.products.create'))->assertOk()
            ->assertSee('Jina la bidhaa')->assertSee('Barcode (si lazima)')->assertSee('Kundi')->assertSee('Kipimo')
            ->assertSee('Bei ya kuuza')->assertSee('Bei ya kununua (hiari)')->assertSee('Hifadhi bidhaa')->assertSee('Mipangilio ya ziada');
    }
}
