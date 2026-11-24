<?php

namespace Tests\Feature\Inventory;

use App\Enums\LocationType;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SystemRole;
use App\Livewire\Purchasing\GoodsReceiptForm;
use App\Livewire\Purchasing\PurchaseOrderForm;
use App\Livewire\Purchasing\PurchaseOrderIndex;
use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\PurchaseOrder;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\SupplierTransaction;
use App\Services\Purchasing\PurchaseOrderService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\CreatesInventory;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** HARDEX-style purchasing & receiving workflow. */
class ReceivePurchaseTest extends TestCase
{
    use CreatesInventory, CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    /** Approved order: Juice 2 cartons x 24 @ 24,000 and Rice 20 pieces @ 500. */
    private function order(): array
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $juice = $this->makeProduct($company, $owner, ['name' => 'Juice', 'sku' => 'JUICE']);
        $carton = $this->packaging($juice, 'ctn', '24');
        $rice = $this->makeProduct($company, $owner, ['name' => 'Rice', 'sku' => 'RICE']);
        $order = $this->approvedOrder($company, $this->headOffice($company), $this->makeSupplier($company), [
            [$juice, $carton, '2', '24000'],
            [$rice, $this->baseUnitOf($rice), '20', '500'],
        ], $owner);

        return [$company, $owner, $order, $juice, $carton, $rice];
    }

    /** The DEFAULT receiving destination: the branch's Sales Floor (by location type). */
    private function receiving(Company $company): StockLocation
    {
        return $this->location($this->headOffice($company), LocationType::SalesFloor);
    }

    public function test_receive_all_fills_outstanding_quantities_and_posts(): void
    {
        [$company, , $order, $juice, , $rice] = $this->order();

        Livewire::test(GoodsReceiptForm::class, ['order' => $order])
            ->assertSet('lines.0.quantity', '')
            ->assertSet('defaultLocation', $this->receiving($company)->uuid)   // Sales Floor preselected
            ->call('receiveAll')
            ->assertSet('lines.0.quantity', '2')
            ->assertSet('lines.1.quantity', '20')
            ->set('deliveryNote', 'DN-1')->set('invoiceNumber', 'INV-1')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $grn = GoodsReceipt::forCompany($company)->sole();
        $this->assertSame(['DN-1', 'INV-1'], [$grn->delivery_note_number, $grn->supplier_invoice_number]);
        $this->assertSame(PurchaseOrderStatus::Received, $order->fresh()->status);
        $this->assertSame('48.0000', $this->onHand($this->receiving($company), $juice));
        $this->assertSame('20.0000', $this->onHand($this->receiving($company), $rice));
    }

    public function test_multiple_partial_receipts_preserve_outstanding_quantities(): void
    {
        [$company, $owner, $order, $juice, , $rice] = $this->order();

        Livewire::test(GoodsReceiptForm::class, ['order' => $order])
            ->set('lines.0.quantity', '1')->set('lines.1.quantity', '5')
            ->call('save')->assertHasNoErrors();
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $order->fresh()->status);

        $this->actingAsTenant($owner, $company);
        // Second delivery: 11 juice in PIECES; Receive All for the rest.
        $second = Livewire::test(GoodsReceiptForm::class, ['order' => $order->fresh()]);
        $this->assertCount(2, $second->get('lines'));
        $second->call('receiveAll')
            ->assertSet('lines.1.quantity', '15');
        $second->set('lines.0.product_unit_id', $this->baseUnitOf($juice)->id)->set('lines.0.quantity', '11')
            ->call('save')->assertHasNoErrors();

        $this->actingAsTenant($owner, $company);
        $third = Livewire::test(GoodsReceiptForm::class, ['order' => $order->fresh()]);
        $this->assertCount(1, $third->get('lines'), 'Rice is complete; only juice remains.');
        // 13 pieces left on a carton line: Receive All switches to pieces.
        $third->call('receiveAll')
            ->assertSet('lines.0.product_unit_id', $this->baseUnitOf($juice)->id)
            ->assertSet('lines.0.quantity', '13')
            ->assertSet('lines.0.unit_cost', '1000')
            ->call('save')->assertHasNoErrors();

        $this->assertSame(PurchaseOrderStatus::Received, $order->fresh()->status);
        $this->assertSame('48.0000', $this->onHand($this->receiving($company), $juice));
        $this->assertSame(3, GoodsReceipt::forCompany($company)->count());
    }

    public function test_over_receiving_is_rejected(): void
    {
        [$company, , $order] = $this->order();

        Livewire::test(GoodsReceiptForm::class, ['order' => $order])
            ->set('lines.0.quantity', '3')   // only 2 cartons ordered
            ->call('save')
            ->assertHasErrors('lines.0.quantity');

        $this->assertSame(0, GoodsReceipt::forCompany($company)->count());
        $this->assertSame(0, StockMovement::forCompany($company)->count());
    }

    public function test_double_submission_posts_only_once(): void
    {
        [$company, , $order, $juice] = $this->order();

        $component = Livewire::test(GoodsReceiptForm::class, ['order' => $order])->set('lines.0.quantity', '1');
        $component->call('save')->assertHasNoErrors();
        $component->call('save'); // double click / retry with the same submission key

        $this->assertSame(1, GoodsReceipt::forCompany($company)->count());
        $this->assertSame('24.0000', $this->onHand($this->receiving($company), $juice));
        $this->assertSame(1, SupplierTransaction::forCompany($company)->count());
    }

    public function test_receiving_locations_must_belong_to_the_order_branch(): void
    {
        [$company, $owner, $order] = $this->order();
        $otherBranch = $this->createBranch($company);
        $foreign = app(TenantContext::class)->withoutTenancy(fn () => $this->createCompany());
        $foreignLocation = $this->location($this->headOffice($foreign));
        $this->actingAsTenant($owner, $company);

        foreach ([$this->location($otherBranch)->uuid, $foreignLocation->uuid] as $uuid) {
            Livewire::test(GoodsReceiptForm::class, ['order' => $order])
                ->set('lines.0.quantity', '1')->set('lines.0.location', $uuid)
                ->call('save')->assertHasErrors('lines.0.location');

            Livewire::test(GoodsReceiptForm::class, ['order' => $order])
                ->set('lines.0.quantity', '1')->set('defaultLocation', $uuid)
                ->call('save')->assertHasErrors('defaultLocation');
        }

        $this->assertSame(0, GoodsReceipt::forCompany($company)->count());
    }

    public function test_default_location_applies_to_all_lines_with_per_line_override(): void
    {
        [$company, , $order, $juice, , $rice] = $this->order();
        $hq = $this->headOffice($company);
        $main = $this->location($hq, LocationType::MainStore);
        $floor = $this->location($hq, LocationType::SalesFloor);

        Livewire::test(GoodsReceiptForm::class, ['order' => $order])
            ->set('defaultLocation', $main->uuid)
            ->assertSet('lines.0.location', $main->uuid)
            ->assertSet('lines.1.location', $main->uuid)
            ->set('lines.1.location', $floor->uuid)
            ->call('receiveAll')
            ->call('save')->assertHasNoErrors();

        $this->assertSame('48.0000', $this->onHand($main, $juice));
        $this->assertSame('20.0000', $this->onHand($floor, $rice));
        $this->assertSame($floor->id, GoodsReceiptLine::forCompany($company)->where('product_id', $rice->id)->value('location_id'));
    }

    public function test_landed_costs_are_allocated_into_inventory_cost_but_not_the_supplier_balance(): void
    {
        [$company, , $order, $juice, , $rice] = $this->order();

        // 48 juice (goods 48,000) and 20 rice (goods 10,000); 680 of costs over 68 base units = 10 per unit.
        $component = Livewire::test(GoodsReceiptForm::class, ['order' => $order])
            ->call('receiveAll')
            ->call('addCost')->set('costs.0.cost_type', 'transport')->set('costs.0.amount', '500')
            ->call('addCost')->set('costs.1.cost_type', 'handling')->set('costs.1.amount', '180');

        $summary = $component->viewData('summary');
        $this->assertSame(2, $summary['selected_products']);
        $this->assertSame('68', $summary['received_quantity']);
        $this->assertSame('58000.00', $summary['goods_value']);
        $this->assertSame('680.00', $summary['additional_costs']);
        $this->assertSame('58680.00', $summary['landed_value']);
        $this->assertSame('862.9412', $summary['landed_cost_per_unit']);
        $this->assertSame(1, $summary['locations']);
        $this->assertSame('0', $summary['remaining_after']);

        $component->call('save')->assertHasNoErrors();

        $grn = GoodsReceipt::forCompany($company)->sole();
        $this->assertSame('680.00', $grn->landed_cost_total);
        $this->assertSame('58000.00', $grn->goods_value);
        $this->assertSame(2, $grn->costs()->count());

        $juiceLine = GoodsReceiptLine::forCompany($company)->where('product_id', $juice->id)->sole();
        $riceLine = GoodsReceiptLine::forCompany($company)->where('product_id', $rice->id)->sole();
        $this->assertSame('480.00', $juiceLine->landed_cost_amount);
        $this->assertSame('200.00', $riceLine->landed_cost_amount);
        $this->assertSame('1010.0000', $juiceLine->landed_unit_cost_base);  // (48,000 + 480) / 48
        $this->assertSame('510.0000', $riceLine->landed_unit_cost_base);    // (10,000 + 200) / 20

        // Inventory (WAC) uses landed cost; supplier price & supplier balance do not.
        $this->assertSame('1010.0000', $this->branchCost($this->headOffice($company), $juice)->average_cost);
        $this->assertSame('1000.0000', $juice->fresh()->purchase_cost, 'Latest SUPPLIER price is kept separate.');
        $this->assertSame('58000.00', $order->supplier->outstandingBalance(), 'Landed costs are not owed to the goods supplier.');
        $this->assertSame('1010.0000', StockMovement::forCompany($company)->where('product_id', $juice->id)->value('unit_cost'));
    }

    public function test_weighted_average_combines_landed_and_plain_receipts(): void
    {
        [$company, $owner, $order, $juice] = $this->order();

        Livewire::test(GoodsReceiptForm::class, ['order' => $order])
            ->set('lines.0.quantity', '1')               // 24 pcs @ 1,000
            ->call('addCost')->set('costs.0.amount', '240') // +10 per piece
            ->call('save')->assertHasNoErrors();

        $this->actingAsTenant($owner, $company);
        Livewire::test(GoodsReceiptForm::class, ['order' => $order->fresh()])
            ->set('lines.0.quantity', '1')               // 24 pcs @ 1,000, no extra costs
            ->call('save')->assertHasNoErrors();

        $this->assertSame('1005.0000', $this->branchCost($this->headOffice($company), $juice)->average_cost); // (24x1010 + 24x1000) / 48
    }

    public function test_quick_receive_and_permissions(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $juice = $this->makeProduct($company, $owner);
        $draft = app(PurchaseOrderService::class)->createDraft($company, [
            'supplier_id' => $this->makeSupplier($company)->id, 'branch_id' => $this->headOffice($company)->id, 'order_date' => today()->toDateString(),
        ], [['product_id' => $juice->id, 'product_unit_id' => $this->baseUnitOf($juice)->id, 'quantity' => '5', 'unit_cost' => '100']], $owner);

        $keeper = $this->addMember($company, SystemRole::StoreKeeper, [$this->headOffice($company)]);
        $cashier = $this->addMember($company, SystemRole::Cashier, [$this->headOffice($company)]);
        $outsider = $this->addMember($company, SystemRole::BranchManager, [$this->createBranch($company)]);

        $this->actingAs($keeper)->get(route('purchasing.orders.receive', $draft))->assertForbidden();   // no quick_receive
        $this->actingAs($cashier)->get(route('purchasing.orders.receive', $draft))->assertForbidden();
        $this->actingAs($outsider)->get(route('purchasing.orders.receive', $draft))->assertForbidden(); // other branch
        $this->actingAs($owner)->get(route('purchasing.orders.receive', $draft))->assertOk()->assertSee('Quick receive');

        $this->actingAsTenant($owner, $company);
        Livewire::test(GoodsReceiptForm::class, ['order' => $draft])->call('receiveAll')->call('save')->assertHasNoErrors();

        $order = $draft->fresh();
        $this->assertSame(PurchaseOrderStatus::Received, $order->status);
        $this->assertSame(['created', 'submitted', 'approved', 'received'], $order->events()->pluck('event')->all());
    }

    public function test_send_po_respects_approval_rules(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $keeper = $this->addMember($company, SystemRole::StoreKeeper, [$this->headOffice($company)]);
        $this->actingAsTenant($owner, $company);
        $juice = $this->makeProduct($company, $owner, ['barcode' => '6001234567899']);
        $supplier = $this->makeSupplier($company);

        // Store keeper: Send PO waits for approval. Double-clicking Save creates one order.
        $this->actingAsTenant($keeper, $company);
        $form = Livewire::test(PurchaseOrderForm::class)->set('supplier', $supplier->uuid)
            ->set('scan', '6001234567899')->call('scanBarcode')
            ->set('scan', '6001234567899')->call('scanBarcode');
        $this->assertSame('2', $form->get('lines.0.quantity'));
        $this->assertSame('2360.00', $form->viewData('totals')['total']); // 2 x 1,000 purchase cost + 18% VAT
        $form->call('save', true)->assertHasNoErrors();
        $form->call('save', true);
        $this->assertSame(1, PurchaseOrder::forCompany($company)->count());
        $this->assertSame(PurchaseOrderStatus::Submitted, PurchaseOrder::forCompany($company)->sole()->status);

        // Company turns approval off: the keeper's Send approves directly (recorded).
        $company->forceFill(['settings' => array_merge($company->settings, ['purchases_require_approval' => false])])->save();
        $this->actingAsTenant($keeper, $company);
        Livewire::test(PurchaseOrderForm::class)->set('supplier', $supplier->uuid)
            ->set('scan', '6001234567899')->call('scanBarcode')->call('save', true)->assertHasNoErrors();
        $second = PurchaseOrder::forCompany($company)->latest('id')->first();
        $this->assertSame(PurchaseOrderStatus::Approved, $second->status);
        $this->assertSame('Approval not required by company settings', $second->events()->where('event', 'approved')->value('notes'));
    }

    public function test_purchases_list_shows_paid_balance_and_state_actions(): void
    {
        [$company, $owner, $order] = $this->order();
        Livewire::test(GoodsReceiptForm::class, ['order' => $order])->set('lines.1.quantity', '10')->call('save'); // 10 rice x 500

        $this->actingAs($owner)->get(route('purchasing.orders.index'))
            ->assertOk()
            ->assertSee('Paid')->assertSee('Balance')
            ->assertSee('5,000.00')          // balance = received goods (no tax on this line)
            ->assertSee('Receive')
            ->assertDontSee('Send PO');      // no draft orders

        $keeper = $this->addMember($company, SystemRole::StoreKeeper, [$this->headOffice($company)]);
        $this->actingAsTenant($keeper, $company);
        Livewire::test(PurchaseOrderIndex::class)->call('cancel', $order->uuid, 'x')->assertForbidden(); // no purchases.approve
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $order->fresh()->status);
    }

    public function test_historical_receipts_without_new_fields_still_display(): void
    {
        [$company, $owner, $order, , $carton] = $this->order();
        $grn = $this->receiveGoods($order, [['purchase_order_line_id' => $order->lines()->first()->id, 'product_unit_id' => $carton->id, 'quantity' => '1']], $owner);

        // Simulate a pre-migration GRN: new columns empty.
        DB::table('goods_receipt_lines')->where('goods_receipt_id', $grn->id)
            ->update(['landed_unit_cost_base' => null, 'location_id' => null, 'goods_value' => 0, 'landed_cost_amount' => 0]);
        DB::table('goods_receipts')->where('id', $grn->id)->update(['delivery_note_number' => null, 'supplier_invoice_number' => null, 'goods_value' => 0]);

        $this->actingAs($owner)->get(route('purchasing.receipts.show', $grn))->assertOk()->assertSee($grn->supplier_reference)->assertSee('1,000.0000');
        $this->actingAs($owner)->get(route('purchasing.receipts.index'))->assertOk()->assertSee($grn->reference);
    }

    public function test_receive_and_transfer_pages_use_responsive_layouts(): void
    {
        [$company, $owner, $order] = $this->order();

        // Structural check only (real responsiveness is verified manually in a browser).
        $receive = $this->actingAs($owner)->get(route('purchasing.orders.receive', $order))->assertOk()->getContent();
        $this->assertStringContainsString('grid-cols-2 gap-3 md:grid-cols-6', $receive);
        $this->assertStringContainsString('sticky bottom-0', $receive);

        $transfers = $this->actingAs($owner)->get(route('inventory.transfers'))->assertOk()->getContent();
        $this->assertStringContainsString('data-barcode-scan', $transfers);
        $this->assertStringContainsString('enterkeyhint="enter"', $transfers);
    }
}
