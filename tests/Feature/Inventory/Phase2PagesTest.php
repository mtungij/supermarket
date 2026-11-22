<?php

namespace Tests\Feature\Inventory;

use App\Enums\BarcodeType;
use App\Enums\SystemRole;
use App\Models\PurchaseOrder;
use App\Services\Catalogue\BarcodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesInventory;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Every Phase 2 page renders over real HTTP, and access follows permissions. */
class Phase2PagesTest extends TestCase
{
    use CreatesInventory, CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    private function fixture(): array
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $juice = $this->makeProduct($company, $owner, ['name' => 'Azam Juice', 'barcode' => '6001234567899']);
        $piece = $this->baseUnitOf($juice);
        $supplier = $this->makeSupplier($company);
        $order = $this->approvedOrder($company, $this->headOffice($company), $supplier, [[$juice, $piece, '10', '1000']], $owner);
        $grn = $this->receiveGoods($order, [['purchase_order_line_id' => $order->lines()->sole()->id, 'product_unit_id' => $piece->id, 'quantity' => '4']], $owner);

        return compact('company', 'owner', 'juice', 'supplier', 'order', 'grn');
    }

    public function test_owner_can_open_every_phase_2_page(): void
    {
        ['company' => $company, 'owner' => $owner, 'juice' => $juice, 'supplier' => $supplier, 'order' => $order, 'grn' => $grn] = $this->fixture();

        $pages = [
            route('catalogue.products.index') => 'Azam Juice',
            route('catalogue.products.create') => 'Product name',
            route('catalogue.products.show', $juice) => 'Units &amp; conversions',
            route('catalogue.products.edit', $juice) => 'Azam Juice',
            route('catalogue.products.labels', $juice) => '6001234567899',
            route('catalogue.categories.index') => 'Fruits &amp; Vegetables',
            route('catalogue.brands.index') => 'Brands',
            route('catalogue.units.index') => 'Kilogram',
            route('catalogue.barcodes.lookup') => 'Barcode or SKU',
            route('purchasing.suppliers.index') => $supplier->name,
            route('purchasing.suppliers.show', $supplier) => $order->reference,
            route('purchasing.orders.index') => $order->reference,
            route('purchasing.orders.create') => 'Scan barcode',
            route('purchasing.orders.print', $order) => 'PURCHASE ORDER',
            route('purchasing.orders.show', $order) => 'Approval audit',
            route('purchasing.orders.receive', $order) => 'Receive All',
            route('purchasing.receipts.index') => $grn->reference,
            route('purchasing.receipts.show', $grn) => 'Cost per base unit',
            route('inventory.stock') => 'Azam Juice',
            route('inventory.movements') => $grn->reference,
            route('inventory.adjustments') => 'Stock adjustments',
            route('inventory.transfers') => 'Stock transfers',
            route('inventory.locations') => 'Receiving Area',
        ];

        foreach ($pages as $url => $text) {
            $this->actingAs($owner)->get($url)->assertOk()->assertSee($text, false);
        }

        $this->actingAs($owner)->get(route('catalogue.products.index'))->assertSee('Purchases')->assertSee('Stock on hand');
    }

    public function test_phase_2_pages_render_in_kiswahili(): void
    {
        ['owner' => $owner] = $this->fixture();
        $owner->forceFill(['locale' => 'sw'])->save();

        $this->actingAs($owner)->get(route('catalogue.products.index'))->assertOk()->assertSee('Bidhaa');
        $this->actingAs($owner)->get(route('inventory.stock'))->assertOk()->assertSee('Stoku iliyopo');
        $this->actingAs($owner)->get(route('purchasing.orders.index'))->assertOk()->assertSee('Manunuzi')->assertSee('Imelipwa');
        $order = PurchaseOrder::query()->withoutGlobalScopes()->latest('id')->first();
        $this->actingAs($owner)->get(route('purchasing.orders.receive', $order->uuid))->assertOk()->assertSee('Pokea Zote');
    }

    public function test_cashier_access_is_limited_to_viewing_products(): void
    {
        ['company' => $company, 'juice' => $juice, 'order' => $order] = $this->fixture();
        $cashier = $this->addMember($company, SystemRole::Cashier, [$this->headOffice($company)]);

        $this->actingAs($cashier)->get(route('catalogue.products.index'))->assertOk();
        $this->actingAs($cashier)->get(route('catalogue.products.show', $juice))->assertOk()->assertDontSee('Set price');
        $this->actingAs($cashier)->get(route('catalogue.products.create'))->assertForbidden();

        foreach (['purchasing.orders.index', 'purchasing.suppliers.index', 'inventory.stock', 'inventory.adjustments', 'inventory.transfers'] as $name) {
            $this->actingAs($cashier)->get(route($name))->assertForbidden();
        }
        $this->actingAs($cashier)->get(route('purchasing.orders.show', $order))->assertForbidden();
    }

    public function test_store_keeper_and_auditor_access(): void
    {
        ['company' => $company, 'order' => $order] = $this->fixture();
        $keeper = $this->addMember($company, SystemRole::StoreKeeper, [$this->headOffice($company)]);
        $auditor = $this->addMember($company, SystemRole::Auditor);

        $this->actingAs($keeper)->get(route('purchasing.orders.create'))->assertOk();
        $this->actingAs($keeper)->get(route('purchasing.orders.receive', $order))->assertOk();
        $this->actingAs($keeper)->get(route('inventory.adjustments'))->assertOk()->assertSee('New adjustment');

        $this->actingAs($auditor)->get(route('inventory.stock'))->assertOk();
        $this->actingAs($auditor)->get(route('purchasing.orders.show', $order))->assertOk()->assertDontSee('Receive goods');
        $this->actingAs($auditor)->get(route('purchasing.orders.create'))->assertForbidden();
        $this->actingAs($auditor)->get(route('catalogue.products.create'))->assertForbidden();
    }

    public function test_branch_staff_cannot_open_documents_of_other_branches(): void
    {
        ['company' => $company, 'order' => $order, 'grn' => $grn] = $this->fixture();
        $other = $this->createBranch($company);
        $manager = $this->addMember($company, SystemRole::BranchManager, [$other]);

        $this->actingAs($manager)->get(route('purchasing.orders.show', $order))->assertForbidden();
        $this->actingAs($manager)->get(route('purchasing.orders.receive', $order))->assertForbidden();
        $this->actingAs($manager)->get(route('purchasing.receipts.show', $grn))->assertForbidden();
        $this->actingAs($manager)->get(route('purchasing.orders.index'))->assertOk()->assertDontSee($order->reference);
    }

    public function test_labels_render_ean13_and_code128(): void
    {
        ['owner' => $owner, 'juice' => $juice] = $this->fixture();
        app(BarcodeService::class)->add($juice, 'SHELF-A1', BarcodeType::Internal);

        $this->actingAs($owner)->get(route('catalogue.products.labels', [$juice, 'copies' => 3]))->assertOk()->assertSee('<svg', false);
        $code128 = $juice->barcodes()->where('barcode', 'SHELF-A1')->sole();
        $this->actingAs($owner)->get(route('catalogue.products.labels', [$juice, 'barcode' => $code128->id, 'layout' => 'roll']))->assertOk()->assertSee('SHELF-A1');
    }
}
