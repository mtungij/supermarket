<?php

namespace Tests\Feature\Inventory;

use App\Enums\GoodsReceiptStatus;
use App\Enums\LocationType;
use App\Enums\PurchaseOrderStatus;
use App\Enums\StockMovementType;
use App\Enums\SystemRole;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InvalidDocumentStateException;
use App\Models\StockMovement;
use App\Models\SupplierTransaction;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Purchasing\PurchaseOrderService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesInventory;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class GoodsReceiptTest extends TestCase
{
    use CreatesInventory, CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    private function setUpOrder(string $cartons = '10', string $cartonCost = '24000'): array
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $juice = $this->makeProduct($company, $owner, ['name' => 'Juice']);
        $carton = $this->packaging($juice, 'ctn', '24');
        $supplier = $this->makeSupplier($company);
        $order = $this->approvedOrder($company, $this->headOffice($company), $supplier, [[$juice, $carton, $cartons, $cartonCost, '18']], $owner);

        return [$company, $owner, $juice, $carton, $order, $order->lines()->sole()];
    }

    public function test_partial_then_full_receiving_across_multiple_deliveries(): void
    {
        [$company, $owner, $juice, $carton, $order, $line] = $this->setUpOrder();
        $receiving = $this->location($this->headOffice($company), LocationType::ReceivingArea);

        $first = $this->receiveGoods($order, [['purchase_order_line_id' => $line->id, 'product_unit_id' => $carton->id, 'quantity' => '4']], $owner);

        $this->assertSame(GoodsReceiptStatus::Posted, $first->status);
        $this->assertSame('96.0000', $this->onHand($receiving, $juice));
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $order->fresh()->status);
        $this->assertSame('96.0000', $line->fresh()->received_quantity_base);

        // Second delivery in PIECES (different unit, same base).
        $this->receiveGoods($order, [['purchase_order_line_id' => $line->id, 'product_unit_id' => $this->baseUnitOf($juice)->id, 'quantity' => '144']], $owner);

        $this->assertSame('240.0000', $this->onHand($receiving, $juice));
        $this->assertSame(PurchaseOrderStatus::Received, $order->fresh()->status);
        $this->assertSame(2, StockMovement::forCompany($company)->where('type', StockMovementType::GoodsReceipt->value)->count());
    }

    public function test_weighted_average_cost_and_cost_snapshots(): void
    {
        [$company, $owner, $juice, $carton, $order, $line] = $this->setUpOrder('10', '24000');
        $branch = $this->headOffice($company);

        // 48 pcs at 1,000 (24,000 / 24).
        $this->receiveGoods($order, [['purchase_order_line_id' => $line->id, 'product_unit_id' => $carton->id, 'quantity' => '2']], $owner);
        $this->assertSame('1000.0000', $this->branchCost($branch, $juice)->average_cost);

        // 24 pcs at 1,300 (invoice price changed to 31,200 per carton).
        $this->receiveGoods($order, [['purchase_order_line_id' => $line->id, 'product_unit_id' => $carton->id, 'quantity' => '1', 'unit_cost' => '31200']], $owner);

        $cost = $this->branchCost($branch, $juice);
        $this->assertSame('72.0000', $cost->quantity);
        $this->assertSame('1100.0000', $cost->average_cost);   // (48x1000 + 24x1300) / 72
        $this->assertSame('1300.0000', $cost->last_cost);
        $this->assertSame('1300.0000', $juice->fresh()->purchase_cost, 'Latest purchase cost');

        // Historical movement costs are never rewritten.
        $movements = StockMovement::forCompany($company)->orderBy('id')->get();
        $this->assertSame('1000.0000', $movements[0]->unit_cost);
        $this->assertSame('1000.0000', $movements[0]->average_cost_after);
        $this->assertSame('24.000000', $movements[0]->conversion_factor);
        $this->assertSame('2.0000', $movements[0]->entered_quantity);
        $this->assertSame('1300.0000', $movements[1]->unit_cost);
    }

    public function test_posting_the_same_grn_twice_never_adds_stock_twice(): void
    {
        [$company, $owner, $juice, $carton, $order, $line] = $this->setUpOrder();
        $receiving = $this->location($this->headOffice($company), LocationType::ReceivingArea);
        $service = app(GoodsReceiptService::class);

        $draft = $service->createDraft($order, ['location_id' => $receiving->id, 'received_date' => today()->toDateString()], [
            ['purchase_order_line_id' => $line->id, 'product_unit_id' => $carton->id, 'quantity' => '5'],
        ], $owner);
        $this->assertSame('0.0000', $this->onHand($receiving, $juice), 'Drafts do not move stock.');

        $service->post($draft, $owner);

        try {
            $service->post($draft, $owner);
            $this->fail('Second post accepted.');
        } catch (InvalidDocumentStateException) {
        }

        $this->assertSame('120.0000', $this->onHand($receiving, $juice));
        $this->assertSame(1, StockMovement::forCompany($company)->count());
        $this->assertSame(1, SupplierTransaction::forCompany($company)->count());
    }

    public function test_over_receipt_is_rejected(): void
    {
        [, $owner, , $carton, $order, $line] = $this->setUpOrder('2');

        $this->expectException(BusinessRuleException::class);
        $this->receiveGoods($order, [['purchase_order_line_id' => $line->id, 'product_unit_id' => $carton->id, 'quantity' => '3']], $owner);
    }

    public function test_two_drafts_cannot_together_exceed_the_order(): void
    {
        [$company, $owner, , $carton, $order, $line] = $this->setUpOrder('2');
        $service = app(GoodsReceiptService::class);
        $header = ['location_id' => $this->location($this->headOffice($company))->id, 'received_date' => today()->toDateString()];
        $lines = [['purchase_order_line_id' => $line->id, 'product_unit_id' => $carton->id, 'quantity' => '2']];

        $a = $service->createDraft($order, $header, $lines, $owner);
        $b = $service->createDraft($order, $header, $lines, $owner);
        $service->post($a, $owner);

        $this->expectException(InvalidDocumentStateException::class); // order is now fully received
        $service->post($b, $owner);
    }

    private function draftOrder($company, $owner, $juice, $carton)
    {
        return app(PurchaseOrderService::class)->createDraft($company, ['supplier_id' => $this->makeSupplier($company, 'Other')->id, 'branch_id' => $this->headOffice($company)->id, 'order_date' => today()->toDateString()], [
            ['product_id' => $juice->id, 'product_unit_id' => $carton->id, 'quantity' => '1', 'unit_cost' => '100'],
        ], $owner);
    }

    public function test_users_without_quick_receive_cannot_receive_unapproved_orders(): void
    {
        [$company, $owner, $juice, $carton] = $this->setUpOrder();
        $draft = $this->draftOrder($company, $owner, $juice, $carton);
        $keeper = $this->addMember($company, SystemRole::StoreKeeper, [$this->headOffice($company)]);
        $this->actingAsTenant($keeper, $company);

        $this->expectException(InvalidDocumentStateException::class);
        $this->receiveGoods($draft, [['purchase_order_line_id' => $draft->lines()->sole()->id, 'product_unit_id' => $carton->id, 'quantity' => '1']], $keeper);
    }

    public function test_quick_receive_approves_and_receives_with_audit_trail(): void
    {
        [$company, $owner, $juice, $carton] = $this->setUpOrder();
        $draft = $this->draftOrder($company, $owner, $juice, $carton);

        $grn = $this->receiveGoods($draft, [['purchase_order_line_id' => $draft->lines()->sole()->id, 'product_unit_id' => $carton->id, 'quantity' => '1']], $owner);

        $this->assertSame(GoodsReceiptStatus::Posted, $grn->status);
        $order = $draft->fresh();
        $this->assertSame(PurchaseOrderStatus::Received, $order->status);
        $this->assertSame($owner->id, $order->approved_by);
        $events = $order->events()->get();
        $this->assertSame(['created', 'submitted', 'approved', 'received'], $events->pluck('event')->all());
        $this->assertSame('Quick receive', $events[2]->notes);
    }

    public function test_closing_a_partially_received_order(): void
    {
        [, $owner, , $carton, $order, $line] = $this->setUpOrder('10');
        $this->receiveGoods($order, [['purchase_order_line_id' => $line->id, 'product_unit_id' => $carton->id, 'quantity' => '6']], $owner);

        $closed = app(PurchaseOrderService::class)->close($order, $owner, 'Supplier out of stock');
        $this->assertSame(PurchaseOrderStatus::Closed, $closed->status);

        $this->expectException(InvalidDocumentStateException::class);
        $this->receiveGoods($order, [['purchase_order_line_id' => $line->id, 'product_unit_id' => $carton->id, 'quantity' => '1']], $owner);
    }

    public function test_supplier_outstanding_balance_includes_tax(): void
    {
        [$company, $owner, , $carton, $order, $line] = $this->setUpOrder('10', '24000');

        $this->receiveGoods($order, [['purchase_order_line_id' => $line->id, 'product_unit_id' => $carton->id, 'quantity' => '2']], $owner);

        // 2 x 24,000 = 48,000 + 18% VAT = 56,640
        $this->assertSame('56640.00', $order->supplier->outstandingBalance());
        $this->assertSame(1, $order->supplier->purchaseOrders()->count());
    }

    public function test_cashier_cannot_receive_and_store_keeper_can(): void
    {
        [$company, $owner, $juice, $carton, $order, $line] = $this->setUpOrder();
        $keeper = $this->addMember($company, SystemRole::StoreKeeper, [$this->headOffice($company)]);
        $cashier = $this->addMember($company, SystemRole::Cashier, [$this->headOffice($company)]);
        $lines = [['purchase_order_line_id' => $line->id, 'product_unit_id' => $carton->id, 'quantity' => '1']];

        $this->actingAsTenant($keeper, $company);
        $this->receiveGoods($order, $lines, $keeper);
        $this->assertSame('24.0000', $this->onHand($this->location($this->headOffice($company), LocationType::ReceivingArea), $juice));

        $this->actingAsTenant($cashier, $company);
        $this->expectException(AuthorizationException::class);
        $this->receiveGoods($order, $lines, $cashier);
    }

    public function test_location_must_belong_to_the_order_branch(): void
    {
        [$company, $owner, , $carton, $order, $line] = $this->setUpOrder();
        $otherBranch = $this->createBranch($company);

        $this->expectException(BusinessRuleException::class);
        $this->receiveGoods($order, [['purchase_order_line_id' => $line->id, 'product_unit_id' => $carton->id, 'quantity' => '1']], $owner, $this->location($otherBranch));
    }
}
