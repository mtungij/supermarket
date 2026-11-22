<?php

namespace Tests\Feature\Inventory;

use App\Enums\AdjustmentReason;
use App\Enums\LocationType;
use App\Exceptions\BusinessRuleException;
use App\Models\StockBatch;
use App\Services\Inventory\InventoryQueries;
use App\Services\Inventory\StockAdjustmentService;
use App\Services\Inventory\StockTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesInventory;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class BatchExpiryTest extends TestCase
{
    use CreatesInventory, CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    private function milkOrder(): array
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $milk = $this->makeProduct($company, $owner, ['name' => 'Fresh Milk', 'track_batches' => true, 'track_expiry' => true]);
        $piece = $this->baseUnitOf($milk);
        $order = $this->approvedOrder($company, $this->headOffice($company), $this->makeSupplier($company), [[$milk, $piece, '100', '1200']], $owner);

        return [$company, $owner, $milk, $piece, $order, $order->lines()->sole()];
    }

    public function test_batch_and_expiry_are_required_and_tracked_separately(): void
    {
        [$company, $owner, $milk, $piece, $order, $line] = $this->milkOrder();

        try {
            $this->receiveGoods($order, [['purchase_order_line_id' => $line->id, 'product_unit_id' => $piece->id, 'quantity' => '10']], $owner);
            $this->fail('Batch-tracked receipt without batch accepted.');
        } catch (BusinessRuleException) {
        }

        $this->receiveGoods($order, [
            ['purchase_order_line_id' => $line->id, 'product_unit_id' => $piece->id, 'quantity' => '10', 'batch_number' => 'b-001', 'expires_on' => today()->addDays(5)->toDateString(), 'manufactured_on' => today()->subDays(2)->toDateString()],
            ['purchase_order_line_id' => $line->id, 'product_unit_id' => $piece->id, 'quantity' => '20', 'batch_number' => 'B-002', 'expires_on' => today()->addDays(90)->toDateString()],
        ], $owner);

        $receiving = $this->location($this->headOffice($company), LocationType::ReceivingArea);
        $b1 = StockBatch::forCompany($company)->where('batch_number', 'B-001')->sole();
        $b2 = StockBatch::forCompany($company)->where('batch_number', 'B-002')->sole();

        $this->assertSame('10.0000', $this->onHand($receiving, $milk, $b1->id));
        $this->assertSame('20.0000', $this->onHand($receiving, $milk, $b2->id));
        $this->assertSame('30.0000', $this->branchCost($this->headOffice($company), $milk)->quantity);

        $expiring = app(InventoryQueries::class)->expiring($this->headOffice($company), 30);
        $this->assertSame(['B-001'], $expiring->pluck('batch_number')->all(), 'Only the batch within 30 days is flagged.');
    }

    public function test_existing_batch_cannot_change_expiry(): void
    {
        [, $owner, , $piece, $order, $line] = $this->milkOrder();
        $this->receiveGoods($order, [['purchase_order_line_id' => $line->id, 'product_unit_id' => $piece->id, 'quantity' => '5', 'batch_number' => 'B-9', 'expires_on' => today()->addDays(10)->toDateString()]], $owner);

        $this->expectException(BusinessRuleException::class);
        $this->receiveGoods($order, [['purchase_order_line_id' => $line->id, 'product_unit_id' => $piece->id, 'quantity' => '5', 'batch_number' => 'B-9', 'expires_on' => today()->addDays(11)->toDateString()]], $owner);
    }

    public function test_expiry_only_products_get_automatic_lots(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $bread = $this->makeProduct($company, $owner, ['name' => 'Bread', 'track_expiry' => true]);
        $piece = $this->baseUnitOf($bread);
        $order = $this->approvedOrder($company, $this->headOffice($company), $this->makeSupplier($company), [[$bread, $piece, '10', '2000']], $owner);

        $this->receiveGoods($order, [['purchase_order_line_id' => $order->lines()->sole()->id, 'product_unit_id' => $piece->id, 'quantity' => '10', 'expires_on' => today()->addDays(3)->toDateString()]], $owner);

        $this->assertSame('EXP-'.today()->addDays(3)->format('Ymd'), StockBatch::forCompany($company)->sole()->batch_number);
    }

    public function test_batch_tracked_movements_require_batch_and_batch_moves_with_transfer(): void
    {
        [$company, $owner, $milk, $piece, $order, $line] = $this->milkOrder();
        $this->receiveGoods($order, [['purchase_order_line_id' => $line->id, 'product_unit_id' => $piece->id, 'quantity' => '10', 'batch_number' => 'B-1', 'expires_on' => today()->addDays(20)->toDateString()]], $owner);
        $batch = StockBatch::forCompany($company)->sole();
        $hq = $this->headOffice($company);
        $receiving = $this->location($hq, LocationType::ReceivingArea);

        try {
            app(StockAdjustmentService::class)->request($receiving, AdjustmentReason::Expired, 'x', [['product_id' => $milk->id, 'product_unit_id' => $piece->id, 'quantity' => '1']], $owner);
            $this->fail('Adjustment without batch accepted.');
        } catch (BusinessRuleException) {
        }

        $transfer = app(StockTransferService::class)->create($receiving, $this->location($hq, LocationType::SalesFloor), [
            ['product_id' => $milk->id, 'product_unit_id' => $piece->id, 'quantity' => '4', 'batch_id' => $batch->id],
        ], $owner);
        app(StockTransferService::class)->dispatch($transfer, $owner);

        $this->assertSame('4.0000', $this->onHand($this->location($hq, LocationType::SalesFloor), $milk, $batch->id));
        $this->assertSame('6.0000', $this->onHand($receiving, $milk, $batch->id));
    }
}
