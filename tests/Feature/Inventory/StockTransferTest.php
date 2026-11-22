<?php

namespace Tests\Feature\Inventory;

use App\Enums\LocationType;
use App\Enums\StockTransferStatus;
use App\Enums\SystemRole;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidDocumentStateException;
use App\Models\StockMovement;
use App\Services\Inventory\InventoryQueries;
use App\Services\Inventory\StockTransferService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesInventory;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class StockTransferTest extends TestCase
{
    use CreatesInventory, CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    private function stocked(): array
    {
        $company = $this->createCompany(['max_branches' => 5]);
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $hq = $this->headOffice($company);
        $mbezi = $this->createBranch($company, ['name' => 'Mbezi']);
        $juice = $this->makeProduct($company, $owner, ['name' => 'Juice']);
        $piece = $this->baseUnitOf($juice);
        $order = $this->approvedOrder($company, $hq, $this->makeSupplier($company), [[$juice, $piece, '100', '1000']], $owner);
        $this->receiveGoods($order, [['purchase_order_line_id' => $order->lines()->sole()->id, 'product_unit_id' => $piece->id, 'quantity' => '100']], $owner, $this->location($hq));

        return [$company, $owner, $hq, $mbezi, $juice, $piece];
    }

    public function test_inter_branch_transfer_lifecycle(): void
    {
        [$company, $owner, $hq, $mbezi, $juice, $piece] = $this->stocked();
        $service = app(StockTransferService::class);
        $source = $this->location($hq);
        $destination = $this->location($mbezi);

        $transfer = $service->create($source, $destination, [['product_id' => $juice->id, 'product_unit_id' => $piece->id, 'quantity' => '30']], $owner);
        $this->assertSame('100.0000', $this->onHand($source, $juice), 'Draft does not move stock.');

        $service->dispatch($transfer, $owner);
        $this->assertSame(StockTransferStatus::InTransit, $transfer->fresh()->status);
        $this->assertSame('70.0000', $this->onHand($source, $juice));
        $this->assertSame('0.0000', $this->onHand($destination, $juice), 'In transit: not yet at destination.');
        $this->assertSame('30000.00', app(InventoryQueries::class)->valuation($hq)['in_transit_out_value']);

        try {
            $service->dispatch($transfer, $owner);
            $this->fail('Dispatched twice.');
        } catch (InvalidDocumentStateException) {
        }

        $service->receive($transfer, $owner);
        $this->assertSame(StockTransferStatus::Received, $transfer->fresh()->status);
        $this->assertSame('30.0000', $this->onHand($destination, $juice));
        $this->assertSame('1000.0000', $this->branchCost($mbezi, $juice)->average_cost, 'Cost travels with the goods.');

        try {
            $service->receive($transfer, $owner);
            $this->fail('Received twice.');
        } catch (InvalidDocumentStateException) {
        }

        $this->assertSame('30.0000', $this->onHand($destination, $juice));
        $this->assertSame(['transfer_out', 'transfer_in'], StockMovement::forCompany($company)->where('reference', $transfer->reference)->orderBy('id')->pluck('type')->map->value->all());
    }

    public function test_same_branch_transfer_completes_immediately(): void
    {
        [, $owner, $hq, , $juice, $piece] = $this->stocked();
        $floor = $this->location($hq, LocationType::SalesFloor);

        $transfer = app(StockTransferService::class)->create($this->location($hq), $floor, [['product_id' => $juice->id, 'product_unit_id' => $piece->id, 'quantity' => '12']], $owner);
        app(StockTransferService::class)->dispatch($transfer, $owner);

        $this->assertSame(StockTransferStatus::Received, $transfer->fresh()->status);
        $this->assertSame('12.0000', $this->onHand($floor, $juice));
        $this->assertSame('88.0000', $this->onHand($this->location($hq), $juice));
        $this->assertSame('100.0000', $this->branchCost($hq, $juice)->quantity, 'Branch total unchanged.');
    }

    public function test_dispatch_cannot_exceed_available_stock(): void
    {
        [, $owner, $hq, $mbezi, $juice, $piece] = $this->stocked();
        $service = app(StockTransferService::class);
        $transfer = $service->create($this->location($hq), $this->location($mbezi), [['product_id' => $juice->id, 'product_unit_id' => $piece->id, 'quantity' => '101']], $owner);

        try {
            $service->dispatch($transfer, $owner);
            $this->fail('Over-dispatch accepted.');
        } catch (InsufficientStockException) {
        }

        $this->assertSame(StockTransferStatus::Draft, $transfer->fresh()->status);
        $this->assertSame('100.0000', $this->onHand($this->location($hq), $juice));
    }

    public function test_receiving_requires_destination_branch_access_and_permission(): void
    {
        [$company, $owner, $hq, $mbezi, $juice, $piece] = $this->stocked();
        $service = app(StockTransferService::class);
        $transfer = $service->create($this->location($hq), $this->location($mbezi), [['product_id' => $juice->id, 'product_unit_id' => $piece->id, 'quantity' => '5']], $owner);
        $service->dispatch($transfer, $owner);

        $hqKeeper = $this->addMember($company, SystemRole::StoreKeeper, [$hq]);
        $this->actingAsTenant($hqKeeper, $company);

        try {
            $service->receive($transfer, $hqKeeper);
            $this->fail('Source-branch staff received at destination.');
        } catch (AuthorizationException) {
        }

        $cashier = $this->addMember($company, SystemRole::Cashier, [$mbezi]);
        $this->actingAsTenant($cashier, $company);
        try {
            $service->receive($transfer, $cashier);
            $this->fail('Cashier received a transfer.');
        } catch (AuthorizationException) {
        }

        $mbeziKeeper = $this->addMember($company, SystemRole::StoreKeeper, [$mbezi]);
        $this->actingAsTenant($mbeziKeeper, $company);
        $service->receive($transfer, $mbeziKeeper);
        $this->assertSame('5.0000', $this->onHand($this->location($mbezi), $juice));
    }

    public function test_cannot_dispatch_from_an_unassigned_branch(): void
    {
        [$company, , $hq, $mbezi, $juice, $piece] = $this->stocked();
        $mbeziKeeper = $this->addMember($company, SystemRole::StoreKeeper, [$mbezi]);
        $this->actingAsTenant($mbeziKeeper, $company);

        $this->expectException(AuthorizationException::class);
        app(StockTransferService::class)->create($this->location($hq), $this->location($mbezi), [['product_id' => $juice->id, 'product_unit_id' => $piece->id, 'quantity' => '5']], $mbeziKeeper);
    }

    public function test_cancelled_transfer_cannot_be_dispatched(): void
    {
        [, $owner, $hq, $mbezi, $juice, $piece] = $this->stocked();
        $service = app(StockTransferService::class);
        $transfer = $service->create($this->location($hq), $this->location($mbezi), [['product_id' => $juice->id, 'product_unit_id' => $piece->id, 'quantity' => '5']], $owner);
        $service->cancel($transfer, $owner);

        $this->expectException(InvalidDocumentStateException::class);
        $service->dispatch($transfer, $owner);
    }
}
