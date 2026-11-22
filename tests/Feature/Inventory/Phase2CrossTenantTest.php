<?php

namespace Tests\Feature\Inventory;

use App\Enums\SystemRole;
use App\Livewire\Catalogue\CategoryIndex;
use App\Livewire\Catalogue\ProductForm;
use App\Livewire\Catalogue\ProductShow;
use App\Livewire\Inventory\AdjustmentIndex;
use App\Livewire\Inventory\TransferIndex;
use App\Livewire\Purchasing\PurchaseOrderForm;
use App\Livewire\Purchasing\PurchaseOrderShow;
use App\Models\Category;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockAdjustment;
use App\Models\StockLocation;
use App\Models\StockTransfer;
use App\Services\Purchasing\PurchaseOrderService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesInventory;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Company A's owner tries to reach company B's Phase 2 data with B's
 * identifiers, through direct HTTP requests and Livewire actions.
 */
class Phase2CrossTenantTest extends TestCase
{
    use CreatesInventory, CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    private function twoCompanies(): array
    {
        $b = $this->createCompany();
        $bOwner = $this->createOwner($b);
        $this->actingAsTenant($bOwner, $b);
        $bProduct = $this->makeProduct($b, $bOwner, ['name' => 'Foreign Product', 'barcode' => '6001234567899']);
        $bSupplier = $this->makeSupplier($b, 'Foreign Supplier');
        $bOrder = $this->approvedOrder($b, $this->headOffice($b), $bSupplier, [[$bProduct, $this->baseUnitOf($bProduct), '5', '100']], $bOwner);
        $bGrn = $this->receiveGoods($bOrder, [['purchase_order_line_id' => $bOrder->lines()->sole()->id, 'product_unit_id' => $this->baseUnitOf($bProduct)->id, 'quantity' => '5']], $bOwner);
        app(TenantContext::class)->clear();

        $a = $this->createCompany();
        $aOwner = $this->createOwner($a);

        return compact('a', 'aOwner', 'b', 'bProduct', 'bSupplier', 'bOrder', 'bGrn');
    }

    public function test_direct_http_requests_with_foreign_identifiers_return_404(): void
    {
        $f = $this->twoCompanies();
        $owner = $f['aOwner'];

        foreach ([
            route('catalogue.products.show', $f['bProduct']),
            route('catalogue.products.edit', $f['bProduct']),
            route('catalogue.products.image', $f['bProduct']),
            route('catalogue.products.labels', $f['bProduct']),
            route('purchasing.suppliers.show', $f['bSupplier']),
            route('purchasing.orders.show', $f['bOrder']),
            route('purchasing.orders.edit', $f['bOrder']),
            route('purchasing.orders.receive', $f['bOrder']),
            route('purchasing.receipts.show', $f['bGrn']),
        ] as $url) {
            $this->actingAs($owner)->get($url)->assertNotFound();
        }

        $this->actingAs($owner)->get(route('catalogue.products.index', ['search' => 'Foreign']))->assertOk()->assertDontSee('Foreign Product');
        $this->actingAs($owner)->get(route('purchasing.orders.index'))->assertOk()->assertDontSee($f['bOrder']->reference);
        $this->actingAs($owner)->get(route('inventory.movements'))->assertOk()->assertDontSee($f['bGrn']->reference);
    }

    public function test_livewire_actions_with_foreign_identifiers_are_rejected(): void
    {
        $f = $this->twoCompanies();
        $this->actingAsTenant($f['aOwner'], $f['a']);
        $bCategory = Category::forCompany($f['b'])->first();

        // Editing another company's category: not found in tenant scope.
        try {
            Livewire::test(CategoryIndex::class)->call('edit', $bCategory->uuid);
            $this->fail('Foreign category was loaded.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1); // rendered as 404 over HTTP
        }

        // Product with B's category uuid.
        Livewire::test(ProductForm::class)
            ->set('name', 'Smuggled')->set('category', $bCategory->uuid)->call('save')
            ->assertHasErrors('category_id');
        $this->assertSame(0, Product::forCompany($f['a'])->count());

        // Purchase order with B's supplier and B's product id.
        Livewire::test(PurchaseOrderForm::class)
            ->set('supplier', $f['bSupplier']->uuid)
            ->set('lines', [['product_id' => $f['bProduct']->id, 'product_unit_id' => $this->baseUnitOf($f['bProduct'])->id, 'quantity' => '1', 'unit_cost' => '1', 'discount_amount' => '0', 'tax_rate' => '0']])
            ->call('save')
            ->assertHasErrors();
        $this->assertSame(0, PurchaseOrder::forCompany($f['a'])->count());

        // Purchase order line with B's product id but A's own supplier.
        $aSupplier = $this->makeSupplier($f['a'], 'Own Supplier');
        Livewire::test(PurchaseOrderForm::class)
            ->set('supplier', $aSupplier->uuid)
            ->set('lines', [['product_id' => $f['bProduct']->id, 'product_unit_id' => $this->baseUnitOf($f['bProduct'])->id, 'quantity' => '1', 'unit_cost' => '1', 'discount_amount' => '0', 'tax_rate' => '0']])
            ->call('save')
            ->assertHasErrors('lines.0.product_id');

        // Barcode scan of B's barcode finds nothing.
        Livewire::test(PurchaseOrderForm::class)->set('scan', '6001234567899')->call('scanBarcode')->assertHasErrors('scan');

        // Adjustment at B's location / transfer to B's location.
        $bLocation = StockLocation::forCompany($f['b'])->first();
        Livewire::test(AdjustmentIndex::class)
            ->set('location', $bLocation->uuid)->set('notes', 'x')
            ->set('lines', [['product_id' => $f['bProduct']->id, 'product_unit_id' => $this->baseUnitOf($f['bProduct'])->id, 'quantity' => '1', 'direction' => '', 'batch_id' => '']])
            ->call('submit')->assertHasErrors('location');
        Livewire::test(TransferIndex::class)
            ->set('source', $this->location($this->headOffice($f['a']))->uuid)->set('destination', $bLocation->uuid)
            ->set('lines', [['product_id' => $f['bProduct']->id, 'product_unit_id' => $this->baseUnitOf($f['bProduct'])->id, 'quantity' => '1', 'batch_id' => '']])
            ->call('save')->assertHasErrors('destination');

        $this->assertSame(0, StockAdjustment::forCompany($f['a'])->count());
        $this->assertSame(0, StockTransfer::withoutTenancy()->count());
    }

    public function test_livewire_mount_with_foreign_model_is_blocked_by_route_binding(): void
    {
        $f = $this->twoCompanies();

        // Livewire update requests replay the original route's middleware; the
        // initial GET already 404s (above). Here: B's product cannot be loaded
        // through the component's tenant-scoped lookup either.
        $this->actingAsTenant($f['aOwner'], $f['a']);
        $this->expectException(ModelNotFoundException::class);
        Livewire::test(ProductShow::class, ['product' => $f['bProduct']]);
    }

    public function test_status_actions_still_check_permissions_server_side(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $product = $this->makeProduct($company, $owner);
        $order = app(PurchaseOrderService::class)->createDraft($company, [
            'supplier_id' => $this->makeSupplier($company)->id, 'branch_id' => $this->headOffice($company)->id, 'order_date' => today()->toDateString(),
        ], [['product_id' => $product->id, 'product_unit_id' => $this->baseUnitOf($product)->id, 'quantity' => '1', 'unit_cost' => '10']], $owner);
        app(PurchaseOrderService::class)->submit($order, $owner);

        $keeper = $this->addMember($company, SystemRole::StoreKeeper, [$this->headOffice($company)]);
        $this->actingAsTenant($keeper, $company);

        // The Approve button is hidden for store keepers, but calling the action directly must still fail.
        Livewire::test(PurchaseOrderShow::class, ['order' => $order])->call('act', 'approve')->assertForbidden();
        $this->assertSame('submitted', $order->fresh()->status->value);
    }
}
