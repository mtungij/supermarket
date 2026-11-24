<?php

namespace Tests\Feature\Inventory;

use App\Enums\LocationType;
use App\Livewire\Inventory\OpeningStockForm;
use App\Livewire\Purchasing\GoodsReceiptForm;
use App\Livewire\Purchasing\PurchaseOrderForm;
use App\Livewire\Purchasing\PurchaseOrderShow;
use App\Models\Company;
use App\Models\OpeningStock;
use App\Models\ProductPrice;
use App\Models\PurchaseOrder;
use App\Models\StockBalance;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\BranchService;
use App\Services\Inventory\OpeningStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesInventory;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Sales Floor default, prefilled editable prices, unchanged prices never applied. */
class OpeningStockPrefillTest extends TestCase
{
    use CreatesInventory, CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany();
        $this->owner = $this->createOwner($this->company);
        $this->actingAsTenant($this->owner, $this->company);
    }

    public function test_sales_floor_is_the_default_by_location_type_and_can_be_changed(): void
    {
        $hq = $this->headOffice($this->company);
        $floor = $this->location($hq, LocationType::SalesFloor);
        $floor->forceFill(['name' => 'Duka la mbele'])->save();                                  // renamed: still found by TYPE

        $form = Livewire::test(OpeningStockForm::class)->assertSet('location', $floor->uuid);
        $store = $this->location($hq, LocationType::MainStore);
        $form->set('location', $store->uuid)->assertSet('location', $store->uuid);               // user may change it

        // receiving: the order branch's Sales Floor by default, too
        $this->actingAsTenant($this->owner, $this->company);
        $p = $this->makeProduct($this->company, $this->owner, ['name' => 'X']);
        $order = $this->approvedOrder($this->company, $hq, $this->makeSupplier($this->company), [[$p, $this->baseUnitOf($p), '2', '100']], $this->owner);
        Livewire::test(GoodsReceiptForm::class, ['order' => $order])->assertSet('defaultLocation', $floor->uuid);
    }

    public function test_unchanged_prefilled_selling_prices_never_touch_the_catalogue(): void
    {
        $p = $this->makeProduct($this->company, $this->owner, ['name' => 'SOAP', 'selling_price' => '2500']);
        $before = ProductPrice::forCompany($this->company)->count();

        // opening stock: prefilled 2500 kept; even with the box ticked nothing is applied
        $form = Livewire::test(OpeningStockForm::class)->call('pickProduct', $p->id)->assertSet('lines.0.proposed_selling_price', '2500')
            ->call('confirmPost');
        $this->assertSame([], $form->get('confirmation')['price_changes']);
        $form->set('applyPrices', true)->call('post')->assertHasNoErrors();
        $this->assertSame($before, ProductPrice::forCompany($this->company)->count());
        $this->assertNull(OpeningStock::forCompany($this->company)->sole()->lines()->sole()->selling_price_applied_at);

        // purchase: prefilled price unchanged -> no proposal on the order page
        $this->actingAsTenant($this->owner, $this->company);
        $po = Livewire::test(PurchaseOrderForm::class)->set('supplier', $this->makeSupplier($this->company)->uuid)
            ->call('openPicker')->call('togglePicked', $p->id)->call('addPicked')
            ->assertSet('lines.0.proposed_selling_price', '2500')->call('save', false)->assertHasNoErrors();
        $order = PurchaseOrder::forCompany($this->company)->sole();
        Livewire::test(PurchaseOrderShow::class, ['order' => $order])->assertDontSee('Proposed selling prices')->assertDontSee('Update selling prices');
    }

    public function test_purchase_prefill_uses_average_cost_and_follows_the_unit(): void
    {
        $hq = $this->headOffice($this->company);
        $juice = $this->makeProduct($this->company, $this->owner, ['name' => 'JUICE', 'purchase_cost' => '900', 'selling_price' => '1500']);
        $carton = $this->packaging($juice, 'ctn', '24');
        // a receipt at 1,000 sets the branch average
        $order = $this->approvedOrder($this->company, $hq, $this->makeSupplier($this->company, 'S1'), [[$juice, $this->baseUnitOf($juice), '10', '1000']], $this->owner);
        $this->receiveGoods($order, [['purchase_order_line_id' => $order->lines()->sole()->id, 'product_unit_id' => $this->baseUnitOf($juice)->id, 'quantity' => '10']], $this->owner);
        $this->actingAsTenant($this->owner, $this->company);

        $po = Livewire::test(PurchaseOrderForm::class)->set('supplier', $this->makeSupplier($this->company, 'S2')->uuid)
            ->call('openPicker')->call('togglePicked', $juice->id)->call('addPicked')
            ->assertSet('lines.0.unit_cost', '1000')->assertSet('lines.0.proposed_selling_price', '1500');
        $po->set('lines.0.product_unit_id', $carton->id)
            ->assertSet('lines.0.unit_cost', '24000')->assertSet('lines.0.proposed_selling_price', '36000');   // per carton
        $po->set('lines.0.quantity', '2')->set('lines.0.tax_rate', '0')->assertSee('48,000.00');

        // purchases never change stock: still 10 after creating the order
        $before = StockMovement::forCompany($this->company)->count();
        $po->call('save', false)->assertHasNoErrors();
        $this->assertSame($before, StockMovement::forCompany($this->company)->count());
    }

    public function test_mixed_document_posts_opening_and_additional_lines_with_exact_balances(): void
    {
        $hq = $this->headOffice($this->company);
        $floor = $this->location($hq, LocationType::SalesFloor);
        $old = $this->makeProduct($this->company, $this->owner, ['name' => 'OLD']);
        $new = $this->makeProduct($this->company, $this->owner, ['name' => 'NEW']);
        $order = $this->approvedOrder($this->company, $hq, $this->makeSupplier($this->company), [[$old, $this->baseUnitOf($old), '6', '500']], $this->owner);
        $this->receiveGoods($order, [['purchase_order_line_id' => $order->lines()->sole()->id, 'product_unit_id' => $this->baseUnitOf($old)->id, 'quantity' => '6']], $this->owner, $floor);
        $this->actingAsTenant($this->owner, $this->company);

        $doc = app(OpeningStockService::class)->saveDraft($this->company, null, ['branch_id' => $hq->id, 'location_id' => $floor->id, 'opening_date' => today()->toDateString()], [
            ['product_id' => $old->id, 'product_unit_id' => $this->baseUnitOf($old)->id, 'quantity' => '4', 'unit_cost' => '700'],
            ['product_id' => $new->id, 'product_unit_id' => $this->baseUnitOf($new)->id, 'quantity' => '9', 'unit_cost' => '300'],
        ], $this->owner);
        $this->assertSame(['additional', 'opening'], $doc->lines()->orderBy('product_id')->pluck('line_type')->all());
        app(OpeningStockService::class)->post($doc, $this->owner);

        $qty = fn ($p) => (string) StockBalance::forCompany($this->company)->where('location_id', $floor->id)->where('product_id', $p->id)->sum('quantity');
        $this->assertSame(['10.0000', '9.0000'], [$qty($old), $qty($new)]);                 // 6 + 4, and 9: nothing double counted
        $this->assertSame(['goods_receipt', 'stock_in'], StockMovement::forCompany($this->company)->where('product_id', $old->id)->orderBy('id')->pluck('type')->map->value->all());
        $this->assertSame(['opening_stock'], StockMovement::forCompany($this->company)->where('product_id', $new->id)->pluck('type')->map->value->all());

        // a foreign/other-branch location is never accepted
        $other = app(BranchService::class)->create($this->company, ['name' => 'Mbezi']);
        $this->expectException(ValidationException::class);
        app(OpeningStockService::class)->saveDraft($this->company, null, ['branch_id' => $hq->id,
            'location_id' => StockLocation::forCompany($this->company)->where('branch_id', $other->id)->value('id'), 'opening_date' => today()->toDateString()],
            [['product_id' => $new->id, 'product_unit_id' => $this->baseUnitOf($new)->id, 'quantity' => '1', 'unit_cost' => '1']], $this->owner);
    }
}
