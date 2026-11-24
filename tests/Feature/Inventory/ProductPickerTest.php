<?php

namespace Tests\Feature\Inventory;

use App\Enums\LocationType;
use App\Enums\SystemRole;
use App\Livewire\Inventory\OpeningStockForm;
use App\Livewire\Purchasing\PurchaseOrderForm;
use App\Livewire\Purchasing\PurchaseOrderShow;
use App\Models\Company;
use App\Models\OpeningStock;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\OpeningStockService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesInventory;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Searchable multi-select product picker + editable lines in
 * Opening stock and Create purchase; proposed selling prices are only
 * applied through explicit, authorised actions.
 */
class ProductPickerTest extends TestCase
{
    use CreatesInventory, CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    private Company $company;

    private User $owner;

    private Product $soap;

    private Product $rice;

    private Product $juice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany();
        $this->owner = $this->createOwner($this->company);
        $this->actingAsTenant($this->owner, $this->company);
        $this->soap = $this->makeProduct($this->company, $this->owner, ['name' => 'FAMILY MEDICATED SOAP', 'barcode' => '6203013430060', 'selling_price' => '2500', 'purchase_cost' => '1800']);
        $this->rice = $this->makeProduct($this->company, $this->owner, ['name' => 'RICE KILOMBERO 5KG', 'selling_price' => '16000']);
        $this->juice = $this->makeProduct($this->company, $this->owner, ['name' => 'AZAM MANGO JUICE', 'selling_price' => '1500']);
    }

    private function po()
    {
        return Livewire::test(PurchaseOrderForm::class)->set('supplier', $this->makeSupplier($this->company, 'Supplier '.uniqid())->uuid);
    }

    private function names($component): array
    {
        return $component->viewData('picker')['rows']->pluck('name')->all();
    }

    public function test_search_by_name_sku_and_barcode_is_company_scoped_and_bounded(): void
    {
        $pick = $this->po()->call('openPicker');

        $this->assertSame(['AZAM MANGO JUICE', 'FAMILY MEDICATED SOAP', 'RICE KILOMBERO 5KG'], $this->names($pick));   // browse
        $this->assertSame(['FAMILY MEDICATED SOAP'], $this->names($pick->set('pickerSearch', 'soap family')));          // every word
        $this->assertSame(['RICE KILOMBERO 5KG'], $this->names($pick->set('pickerSearch', strtolower($this->rice->sku))));
        $this->assertSame(['FAMILY MEDICATED SOAP'], $this->names($pick->set('pickerSearch', '62030134')));            // barcode prefix
        $pick->set('pickerSearch', 'zzz')->assertSee('No products match');

        // another company's product never appears and cannot be ticked
        app(TenantContext::class)->clear();
        $other = $this->createCompany();
        $otherOwner = $this->createOwner($other);
        $this->actingAsTenant($otherOwner, $other);
        $foreign = $this->makeProduct($other, $otherOwner, ['name' => 'FOREIGN SOAP']);
        $this->actingAsTenant($this->owner, $this->company);
        $pick = $this->po()->call('openPicker')->set('pickerSearch', 'soap');
        $this->assertSame(['FAMILY MEDICATED SOAP'], $this->names($pick));
        $pick->call('togglePicked', $foreign->id)->assertSet('pickerSelected', []);

        // bounded pages of 25
        foreach (range(1, 30) as $n) {
            $this->makeProduct($this->company, $this->owner, ['name' => sprintf('BULK ITEM %02d', $n)]);
        }
        $page = $this->po()->call('openPicker')->set('pickerSearch', 'bulk');
        $this->assertCount(25, $this->names($page));
        $this->assertTrue($page->viewData('picker')['more']);
        $this->assertCount(30, $this->names($page->call('showMorePicker')));
    }

    public function test_multi_select_survives_searches_and_never_adds_duplicates(): void
    {
        $po = $this->po()->call('openPicker')
            ->set('pickerSearch', 'soap')->call('togglePicked', $this->soap->id)
            ->set('pickerSearch', 'rice')->call('togglePicked', $this->rice->id)
            ->assertSet('pickerSelected', [$this->soap->id => true, $this->rice->id => true])
            ->assertSee('products selected')
            ->call('addPicked')->assertSet('pickerOpen', false)->assertSet('pickerSelected', []);

        $this->assertSame([$this->soap->id, $this->rice->id], array_column($po->get('lines'), 'product_id'));   // alphabetical
        $this->assertSame(['1', '1'], array_column($po->get('lines'), 'quantity'));

        // picking the same product again does not duplicate it
        $po->call('openPicker')->call('togglePicked', $this->soap->id)->call('togglePicked', $this->juice->id)->call('addPicked');
        $this->assertCount(3, $po->get('lines'));
        $this->assertStringContainsString('1 was already in the list', $po->get('pickerMessage'));
        // ...and the scanner still works next to it
        $po->set('scan', '6203013430060')->call('scanBarcode');
        $this->assertSame('2', collect($po->get('lines'))->firstWhere('product_id', $this->soap->id)['quantity']);
    }

    public function test_purchase_lines_show_stock_and_prices_and_recalculate_totals(): void
    {
        $this->stockAt($this->soap, '12', '1800');      // a received GRN: branch average 1,800
        $po = $this->po()->call('openPicker')->call('togglePicked', $this->soap->id)->call('addPicked');

        $line = $po->get('lines')[0];
        $this->assertSame('1800', $line['unit_cost']);                                // prefilled from the last receipt cost
        $info = $po->viewData('info')[0];
        $this->assertSame(['12', '1800.00', 'average', '2500.00'], [$info['stock'], $info['buying'], $info['buying_source'], $info['selling']]);

        $po->set('lines.0.quantity', '10')->set('lines.0.unit_cost', '1,850.50')->set('lines.0.tax_rate', '0')
            ->assertSee('18,505.00');                                                    // 10 x 1,850.50 (decimal maths)
        $this->assertSame('18505.00', $po->instance()->totals()['total']);

        // carton: unit conversion
        $carton = $this->packaging($this->soap, 'ctn', '24');
        $po->set('lines.0.product_unit_id', $carton->id)->set('lines.0.quantity', '2')->set('lines.0.unit_cost', '43200')
            ->call('save', false)->assertHasNoErrors();
        $line = PurchaseOrder::forCompany($this->company)->latest('id')->first()->lines()->sole();
        $this->assertSame(['48.0000', '86400.00'], [$line->quantity_base, $line->line_subtotal]);
    }

    public function test_purchase_never_changes_stock_or_prices_until_received_or_explicitly_applied(): void
    {
        $po = $this->po()->call('openPicker')->call('togglePicked', $this->soap->id)->call('addPicked')
            ->set('lines.0.quantity', '6')->set('lines.0.proposed_selling_price', '2,700')
            ->call('save', true)->assertHasNoErrors();
        $order = PurchaseOrder::forCompany($this->company)->sole();
        $this->assertSame('2700.00', $order->lines()->sole()->proposed_selling_price);
        $this->assertSame(0, StockMovement::forCompany($this->company)->where('product_id', $this->soap->id)->count());
        $this->assertSame('2500.00', $this->soap->fresh()->selling_price);

        // draft reopens with the proposal; receiving adds stock but still no price change
        $this->actingAsTenant($this->owner, $this->company);
        $this->receiveGoods($order->fresh(), [['purchase_order_line_id' => $order->lines()->sole()->id, 'product_unit_id' => $this->baseUnitOf($this->soap)->id, 'quantity' => '6']], $this->owner);
        $this->assertSame(1, StockMovement::forCompany($this->company)->where('product_id', $this->soap->id)->where('type', 'goods_receipt')->count());
        $this->assertSame('2500.00', $this->soap->fresh()->selling_price);

        // store keeper cannot apply prices
        $keeper = $this->addMember($this->company, SystemRole::StoreKeeper, [$this->headOffice($this->company)]);
        $this->actingAsTenant($keeper, $this->company);
        Livewire::test(PurchaseOrderShow::class, ['order' => $order])->assertDontSee('data-apply-prices', false)->call('applyPrices')->assertForbidden();

        // owner applies explicitly -> price history, once
        $this->actingAsTenant($this->owner, $this->company);
        Livewire::test(PurchaseOrderShow::class, ['order' => $order])->assertSee('Update selling prices (1)')
            ->set('confirmingPrices', true)->call('applyPrices')->assertSee('1 selling price updated');
        $this->assertSame('2700.00', $this->soap->fresh()->selling_price);
        $this->assertSame('From purchase order '.$order->reference, ProductPrice::forCompany($this->company)->where('product_id', $this->soap->id)->latest('id')->value('notes'));
        $this->assertNotNull($order->lines()->sole()->selling_price_applied_at);
        Livewire::test(PurchaseOrderShow::class, ['order' => $order])->assertDontSee('Update selling prices')->assertSee('Applied');
    }

    public function test_opening_stock_picker_respects_eligibility_location_stock_and_drafts(): void
    {
        $this->stockAt($this->rice, '3', '14000');        // rice already trading: not eligible
        $form = Livewire::test(OpeningStockForm::class)->call('openPicker');
        $stock = $form->viewData('picker')['stock'];
        $this->assertSame(['0', '3'], [$stock[$this->soap->id] ?? '0', $stock[$this->rice->id] ?? null]);

        $form->call('togglePicked', $this->soap->id)->call('togglePicked', $this->rice->id)->call('togglePicked', $this->juice->id)
            ->call('addPicked')->assertSet('pickerOpen', false);                      // nothing refused any more
        $this->assertSame([$this->juice->id, $this->soap->id, $this->rice->id], array_column($form->get('lines'), 'product_id'));
        // prefilled, editable: cost (average > last purchase > reference) and the current selling price
        $this->assertSame(['1000', '1800', '14000'], array_column($form->get('lines'), 'unit_cost'));
        $this->assertSame(['1500', '2500', '16000'], array_column($form->get('lines'), 'proposed_selling_price'));
        $form->assertSee('Additional stock in');                                          // rice: already trading

        $form->set('lines.0.quantity', '24')->set('lines.0.unit_cost', '900')->set('lines.0.proposed_selling_price', '1600')
            ->set('lines.1.quantity', '10')->set('lines.1.unit_cost', '1700')
            ->assertSee('52,600.00')                                                     // 24x900 + 10x1700 + 1x14000
            ->call('saveDraft')->assertHasNoErrors();
        $doc = OpeningStock::forCompany($this->company)->sole();
        $this->assertSame(['opening', 'opening', 'additional'], $doc->lines()->orderBy('id')->pluck('line_type')->all());
        Livewire::test(OpeningStockForm::class, ['openingStock' => $doc])->assertSet('lines.0.proposed_selling_price', '1600')->assertSet('lines.1.proposed_selling_price', '2500');
        $this->assertSame(0, StockMovement::forCompany($this->company)->whereIn('type', ['opening_stock', 'stock_in'])->count());   // drafts change nothing
    }

    public function test_opening_stock_post_changes_prices_only_when_explicitly_confirmed(): void
    {
        $make = fn (Product $p, string $price) => $this->actingAsTenant($this->owner, $this->company) && false ?: app(OpeningStockService::class)->saveDraft($this->company, null, [
            'branch_id' => $this->headOffice($this->company)->id, 'location_id' => $this->location($this->headOffice($this->company), LocationType::SalesFloor)->id,
            'opening_date' => today()->toDateString()], [['product_id' => $p->id, 'product_unit_id' => $this->baseUnitOf($p)->id, 'quantity' => '5', 'unit_cost' => '1000', 'proposed_selling_price' => $price]], $this->owner);

        // not ticked: stock posted, price unchanged
        $doc = $make($this->juice, '1800');
        $form = Livewire::test(OpeningStockForm::class, ['openingStock' => $doc])->call('confirmPost')->assertSet('applyPrices', false);
        $this->assertSame([['product' => 'AZAM MANGO JUICE', 'unit' => 'Piece', 'from' => '1500.00', 'to' => '1800.00']], $form->get('confirmation')['price_changes']);
        $form->assertSee('Also update these catalogue selling prices')->call('post')->assertHasNoErrors();
        $this->assertSame(['posted', '1500.00'], [$doc->fresh()->status->value, $this->juice->fresh()->selling_price]);

        // ticked: same transaction updates the price with history
        $doc = $make($this->soap, '2600');
        Livewire::test(OpeningStockForm::class, ['openingStock' => $doc])->call('confirmPost')->set('applyPrices', true)->call('post')->assertHasNoErrors();
        $this->assertSame('2600.00', $this->soap->fresh()->selling_price);
        $this->assertSame('From opening stock '.$doc->reference, ProductPrice::forCompany($this->company)->where('product_id', $this->soap->id)->latest('id')->value('notes'));

        // a poster without products.prices.manage cannot apply them
        $keeper = $this->addMember($this->company, SystemRole::StoreKeeper, [$this->headOffice($this->company)]);
        $draft = $make($this->rice, '17000');
        $this->actingAsTenant($keeper, $this->company);
        $this->expectException(AuthorizationException::class);
        app(OpeningStockService::class)->post($draft, $keeper, applyPrices: true);
    }

    public function test_invalid_proposed_prices_are_rejected_server_side(): void
    {
        $this->po()->call('openPicker')->call('togglePicked', $this->soap->id)->call('addPicked')
            ->set('lines.0.proposed_selling_price', '-5')->call('save', false)->assertHasErrors('lines.0.proposed_selling_price');
        $this->assertSame(0, PurchaseOrder::forCompany($this->company)->count());

        Livewire::test(OpeningStockForm::class)->call('openPicker')->call('togglePicked', $this->soap->id)->call('addPicked')
            ->set('lines.0.unit_cost', '100')->set('lines.0.proposed_selling_price', 'abc')->call('saveDraft')->assertHasErrors('lines.0.proposed_selling_price');
    }

    public function test_picker_and_lines_render_for_desktop_and_mobile(): void
    {
        $this->po()->call('openPicker')
            ->assertSee('data-product-picker', false)->assertSee('role="dialog"', false)->assertSee('x-trap.noscroll', false)
            ->assertSee('sm:max-w-3xl', false)->assertSee('h-dvh', false)->assertSee('x-on:keydown.escape.prevent.stop', false)
            ->assertSee('x-on:keydown.ctrl.enter.prevent', false)->assertSee('Name, SKU or barcode')
            ->call('togglePicked', $this->soap->id)->call('addPicked')
            ->assertSee('data-lines-table', false)->assertSee('data-lines-cards', false)->assertSee('sticky top-0', false)
            ->assertSee('current below')->assertSee('In stock')->assertSee('New selling')->assertSee('xl:block', false)->assertSee('xl:hidden', false);

        Livewire::test(OpeningStockForm::class)->call('openPicker')->call('togglePicked', $this->soap->id)->call('addPicked')
            ->assertSee('data-lines-table', false)->assertSee('xl:grid-cols-[minmax(0,1fr)', false)->assertSee('In stock here')->assertSee('data-lines-footer', false);

        $this->owner->forceFill(['locale' => 'sw'])->save();
        app()->setLocale('sw');
        $this->po()->call('openPicker')->assertSee('Chagua bidhaa')->assertSee('Ongeza zilizochaguliwa');
    }

    private function stockAt(Product $p, string $qty, string $cost): void
    {
        $order = $this->approvedOrder($this->company, $this->headOffice($this->company), $this->makeSupplier($this->company, 'S'.uniqid()), [[$p, $this->baseUnitOf($p), $qty, $cost]], $this->owner);
        $this->receiveGoods($order, [['purchase_order_line_id' => $order->lines()->sole()->id, 'product_unit_id' => $this->baseUnitOf($p)->id, 'quantity' => $qty]], $this->owner,
            $this->location($this->headOffice($this->company), LocationType::SalesFloor));
        $this->actingAsTenant($this->owner, $this->company);
    }
}
