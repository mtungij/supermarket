<?php

namespace Tests\Feature\Inventory;

use App\Enums\AdjustmentReason;
use App\Enums\StockAdjustmentStatus;
use App\Enums\SystemRole;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidDocumentStateException;
use App\Models\StockMovement;
use App\Services\Inventory\StockAdjustmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesInventory;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class StockAdjustmentTest extends TestCase
{
    use CreatesInventory, CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    /** Company with 48 pieces of Juice in the receiving area. */
    private function stocked(): array
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $juice = $this->makeProduct($company, $owner, ['name' => 'Juice']);
        $piece = $this->baseUnitOf($juice);
        $order = $this->approvedOrder($company, $this->headOffice($company), $this->makeSupplier($company), [[$juice, $piece, '48', '1000']], $owner);
        $grn = $this->receiveGoods($order, [['purchase_order_line_id' => $order->lines()->sole()->id, 'product_unit_id' => $piece->id, 'quantity' => '48']], $owner);

        return [$company, $owner, $juice, $piece, $grn->location];
    }

    public function test_adjustment_requires_approval_before_stock_changes(): void
    {
        [$company, $owner, $juice, $piece, $location] = $this->stocked();
        $keeper = $this->addMember($company, SystemRole::StoreKeeper, [$this->headOffice($company)]);
        $service = app(StockAdjustmentService::class);

        $this->actingAsTenant($keeper, $company);
        $request = $service->request($location, AdjustmentReason::Damaged, 'Crushed cartons in delivery', [
            ['product_id' => $juice->id, 'product_unit_id' => $piece->id, 'quantity' => '5'],
        ], $keeper);

        $this->assertSame(StockAdjustmentStatus::PendingApproval, $request->status);
        $this->assertSame('48.0000', $this->onHand($location, $juice), 'No stock change before approval.');

        // Store keeper cannot approve.
        try {
            $service->approve($request, $keeper);
            $this->fail('Store keeper approved an adjustment.');
        } catch (AuthorizationException) {
        }

        $this->actingAsTenant($owner, $company);
        $posted = $service->approve($request, $owner);

        $this->assertSame(StockAdjustmentStatus::Posted, $posted->status);
        $this->assertSame('43.0000', $this->onHand($location, $juice));
        $this->assertSame($keeper->id, $posted->requested_by);
        $this->assertSame($owner->id, $posted->approved_by);
        $movement = StockMovement::forCompany($company)->where('type', 'adjustment_out')->sole();
        $this->assertSame('-5.0000', $movement->quantity);
        $this->assertSame('1000.0000', $movement->unit_cost);

        // Already posted: cannot be approved again.
        $this->expectException(InvalidDocumentStateException::class);
        $service->approve($request, $owner);
    }

    public function test_rejected_adjustment_does_not_change_stock(): void
    {
        [$company, $owner, $juice, $piece, $location] = $this->stocked();
        $service = app(StockAdjustmentService::class);

        $request = $service->request($location, AdjustmentReason::Lost, 'Missing after count', [
            ['product_id' => $juice->id, 'product_unit_id' => $piece->id, 'quantity' => '2'],
        ], $owner);
        $service->reject($request, $owner, 'Found in back store');

        $this->assertSame(StockAdjustmentStatus::Rejected, $request->fresh()->status);
        $this->assertSame('48.0000', $this->onHand($location, $juice));
    }

    public function test_found_and_count_corrections(): void
    {
        [$company, $owner, $juice, $piece, $location] = $this->stocked();
        $service = app(StockAdjustmentService::class);

        $found = $service->request($location, AdjustmentReason::Found, 'Found behind shelf', [
            ['product_id' => $juice->id, 'product_unit_id' => $piece->id, 'quantity' => '3'],
        ], $owner);
        $service->approve($found, $owner);
        $this->assertSame('51.0000', $this->onHand($location, $juice));

        $count = $service->request($location, AdjustmentReason::CountCorrection, 'Monthly stock count', [
            ['product_id' => $juice->id, 'product_unit_id' => $piece->id, 'quantity' => '1', 'direction' => 'out'],
        ], $owner);
        $service->approve($count, $owner);
        $this->assertSame('50.0000', $this->onHand($location, $juice));
    }

    public function test_direction_must_match_reason(): void
    {
        [, $owner, $juice, $piece, $location] = $this->stocked();

        $this->expectException(BusinessRuleException::class);
        app(StockAdjustmentService::class)->request($location, AdjustmentReason::Damaged, 'x', [
            ['product_id' => $juice->id, 'product_unit_id' => $piece->id, 'quantity' => '1', 'direction' => 'in'],
        ], $owner);
    }

    public function test_reason_notes_are_required(): void
    {
        [, $owner, $juice, $piece, $location] = $this->stocked();

        $this->expectException(BusinessRuleException::class);
        app(StockAdjustmentService::class)->request($location, AdjustmentReason::Damaged, '   ', [
            ['product_id' => $juice->id, 'product_unit_id' => $piece->id, 'quantity' => '1'],
        ], $owner);
    }

    public function test_insufficient_stock_is_rejected_at_approval(): void
    {
        [$company, $owner, $juice, $piece, $location] = $this->stocked();
        $service = app(StockAdjustmentService::class);

        $request = $service->request($location, AdjustmentReason::Expired, 'Expired stock', [
            ['product_id' => $juice->id, 'product_unit_id' => $piece->id, 'quantity' => '49'],
        ], $owner);

        try {
            $service->approve($request, $owner);
            $this->fail('Negative stock allowed.');
        } catch (InsufficientStockException) {
        }

        $this->assertSame('48.0000', $this->onHand($location, $juice));
        $this->assertSame(StockAdjustmentStatus::PendingApproval, $request->fresh()->status, 'Rolled back completely.');
    }

    public function test_cashier_cannot_request_adjustments(): void
    {
        [$company, , $juice, $piece, $location] = $this->stocked();
        $cashier = $this->addMember($company, SystemRole::Cashier, [$this->headOffice($company)]);
        $this->actingAsTenant($cashier, $company);

        $this->expectException(AuthorizationException::class);
        app(StockAdjustmentService::class)->request($location, AdjustmentReason::Damaged, 'x', [
            ['product_id' => $juice->id, 'product_unit_id' => $piece->id, 'quantity' => '1'],
        ], $cashier);
    }
}
