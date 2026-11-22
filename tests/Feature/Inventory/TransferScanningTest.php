<?php

namespace Tests\Feature\Inventory;

use App\Enums\LocationType;
use App\Enums\StockTransferStatus;
use App\Livewire\Inventory\AdjustmentIndex;
use App\Livewire\Inventory\TransferIndex;
use App\Livewire\Purchasing\PurchaseOrderForm;
use App\Models\Company;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\Inventory\StockTransferService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesInventory;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Stock-transfer barcode scanning (bug: scanned code populated the input but added nothing). */
class TransferScanningTest extends TestCase
{
    use CreatesInventory, CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    /** 10 pieces of a product with barcode 6203013430060 in the Receiving Area. */
    private function stocked(): array
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $soda = $this->makeProduct($company, $owner, ['name' => 'Soda 500ml', 'barcode' => '6203013430060']);
        $piece = $this->baseUnitOf($soda);
        $hq = $this->headOffice($company);
        $order = $this->approvedOrder($company, $hq, $this->makeSupplier($company), [[$soda, $piece, '10', '700']], $owner);
        $this->receiveGoods($order, [['purchase_order_line_id' => $order->lines()->sole()->id, 'product_unit_id' => $piece->id, 'quantity' => '10']], $owner);

        return [$company, $owner, $soda, $piece, $hq];
    }

    public function test_scanning_with_enter_adds_the_product_with_quantity_one_and_clears_input(): void
    {
        [, , $soda, $piece, $hq] = $this->stocked();

        Livewire::test(TransferIndex::class)
            ->call('create')
            ->assertDispatched('scan-focus')
            ->set('source', $this->location($hq, LocationType::ReceivingArea)->uuid)
            ->set('scan', '6203013430060')
            ->call('scanBarcode')               // what wire:keydown.enter triggers
            ->assertHasNoErrors()
            ->assertSet('scan', '')
            ->assertDispatched('scan-focus')
            ->assertSet('lines', [['product_id' => $soda->id, 'product_unit_id' => $piece->id, 'batch_id' => '', 'quantity' => '1']]);
    }

    public function test_repeated_scans_increment_quantity_instead_of_duplicating(): void
    {
        [, , , , $hq] = $this->stocked();

        $component = Livewire::test(TransferIndex::class)->call('create')
            ->set('source', $this->location($hq, LocationType::ReceivingArea)->uuid);

        foreach (range(1, 3) as $ignored) {
            $component->set('scan', '6203013430060')->call('scanBarcode');
        }

        $this->assertCount(1, $component->get('lines'));
        $this->assertSame('3', $component->get('lines.0.quantity'));
    }

    public function test_unknown_and_foreign_barcodes_show_a_clear_error(): void
    {
        [, , , , $hq] = $this->stocked();
        app(TenantContext::class)->clear();
        $other = $this->createCompany();
        $otherOwner = $this->createOwner($other);
        $this->actingAsTenant($otherOwner, $other);
        $this->makeProduct($other, $otherOwner, ['barcode' => '6001234567899']);
        app(TenantContext::class)->clear();

        [$company, $owner] = [Company::query()->orderBy('id')->first(), null];
        $owner = User::query()->whereHas('memberships', fn ($q) => $q->where('company_id', $company->id))->first();
        $this->actingAsTenant($owner, $company);

        foreach (['0000000000000', '6001234567899'] as $code) {
            Livewire::test(TransferIndex::class)->call('create')
                ->set('source', $this->location($hq, LocationType::ReceivingArea)->uuid)
                ->set('scan', $code)->call('scanBarcode')
                ->assertHasErrors('scan')
                ->assertSet('lines', [])
                ->assertSet('scan', '');
        }
    }

    public function test_scanning_requires_a_source_and_available_stock(): void
    {
        [, , $soda, $piece, $hq] = $this->stocked();

        Livewire::test(TransferIndex::class)->call('create')
            ->set('scan', '6203013430060')->call('scanBarcode')
            ->assertHasErrors('scan')->assertSet('lines', []);

        // Main Store has none of it.
        Livewire::test(TransferIndex::class)->call('create')
            ->set('source', $this->location($hq, LocationType::MainStore)->uuid)
            ->set('scan', '6203013430060')->call('scanBarcode')
            ->assertHasErrors('scan')->assertSet('lines', []);

        // Cannot scan beyond the 10 available.
        $component = Livewire::test(TransferIndex::class)->call('create')
            ->set('source', $this->location($hq, LocationType::ReceivingArea)->uuid)
            ->set('lines', [['product_id' => $soda->id, 'product_unit_id' => $piece->id, 'batch_id' => '', 'quantity' => '10']])
            ->set('scan', '6203013430060')->call('scanBarcode')
            ->assertHasErrors('scan');
        $this->assertSame('10', $component->get('lines.0.quantity'));
    }

    public function test_scanned_items_save_as_draft_and_are_not_dispatched_automatically(): void
    {
        [$company, , $soda, , $hq] = $this->stocked();
        $receiving = $this->location($hq, LocationType::ReceivingArea);

        Livewire::test(TransferIndex::class)->call('create')
            ->set('source', $receiving->uuid)
            ->set('destination', $this->location($hq, LocationType::SalesFloor)->uuid)
            ->set('scan', '6203013430060')->call('scanBarcode')
            ->set('scan', '6203013430060')->call('scanBarcode')
            ->assertHasNoErrors();

        $this->assertSame(0, StockTransfer::forCompany($company)->count(), 'Scanning never creates or dispatches a transfer.');
        $this->assertSame('10.0000', $this->onHand($receiving, $soda));
    }

    public function test_receiving_area_to_sales_floor_keeps_branch_total(): void
    {
        [$company, $owner, $soda, , $hq] = $this->stocked();
        $receiving = $this->location($hq, LocationType::ReceivingArea);
        $floor = $this->location($hq, LocationType::SalesFloor);

        $component = Livewire::test(TransferIndex::class)->call('create')
            ->set('source', $receiving->uuid)->set('destination', $floor->uuid)
            ->set('scan', '6203013430060')->call('scanBarcode')
            ->set('lines.0.quantity', '4')
            ->call('save', false)
            ->assertHasNoErrors();

        $transfer = StockTransfer::forCompany($company)->sole();
        $this->assertSame(StockTransferStatus::Draft, $transfer->status);

        // Double-submitting the same form returns the same draft.
        $component->call('save', false);
        $this->assertSame(1, StockTransfer::forCompany($company)->count());

        $this->actingAsTenant($owner, $company); // Livewire test requests end by clearing the tenant context
        app(StockTransferService::class)->dispatch($transfer, $owner);

        $this->assertSame('6.0000', $this->onHand($receiving, $soda));
        $this->assertSame('4.0000', $this->onHand($floor, $soda));
        $this->assertSame('10.0000', $this->branchCost($hq, $soda)->quantity, 'Within-branch transfers leave branch stock unchanged.');
    }

    public function test_save_rejects_quantities_above_available_stock(): void
    {
        [$company, , , , $hq] = $this->stocked();

        Livewire::test(TransferIndex::class)->call('create')
            ->set('source', $this->location($hq, LocationType::ReceivingArea)->uuid)
            ->set('destination', $this->location($hq, LocationType::SalesFloor)->uuid)
            ->set('scan', '6203013430060')->call('scanBarcode')
            ->set('lines.0.quantity', '11')
            ->call('save', false)
            ->assertHasErrors('lines.0.quantity');

        $this->assertSame(0, StockTransfer::forCompany($company)->count());
    }

    public function test_adjustment_and_purchase_forms_scan_the_same_way(): void
    {
        $this->stocked();

        $adjustment = Livewire::test(AdjustmentIndex::class)->call('create')
            ->set('scan', '6203013430060')->call('scanBarcode')
            ->set('scan', '6203013430060')->call('scanBarcode');
        $this->assertCount(1, $adjustment->get('lines'));
        $this->assertSame('2', $adjustment->get('lines.0.quantity'));

        $purchase = Livewire::test(PurchaseOrderForm::class)
            ->set('scan', '6203013430060')->call('scanBarcode')
            ->set('scan', ' 6203013430060 ')->call('scanBarcode')
            ->assertSet('scan', '')
            ->assertDispatched('scan-focus');
        $this->assertCount(1, $purchase->get('lines'));
        $this->assertSame('2', $purchase->get('lines.0.quantity'));
    }
}
