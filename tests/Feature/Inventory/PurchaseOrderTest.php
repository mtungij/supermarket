<?php

namespace Tests\Feature\Inventory;

use App\Enums\PurchaseOrderStatus;
use App\Enums\SystemRole;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InvalidDocumentStateException;
use App\Models\DocumentEvent;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Services\Purchasing\PurchaseOrderService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesInventory;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    use CreatesInventory, CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    private function draft($company, $owner, array $overrides = [], ?array $lines = null)
    {
        $product = $this->makeProduct($company, $owner);
        $carton = $this->packaging($product, 'ctn', '24');

        return app(PurchaseOrderService::class)->createDraft($company, array_merge([
            'supplier_id' => $this->makeSupplier($company)->id,
            'branch_id' => $this->headOffice($company)->id,
            'order_date' => today()->toDateString(),
        ], $overrides), $lines ?? [
            ['product_id' => $product->id, 'product_unit_id' => $carton->id, 'quantity' => '3', 'unit_cost' => '24000.50', 'discount_amount' => '1000', 'tax_rate' => '18'],
        ], $owner);
    }

    public function test_totals_use_exact_decimal_arithmetic(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);

        $order = $this->draft($company, $owner);
        $line = $order->lines()->sole();

        $this->assertSame('PO-000001', $order->reference);
        $this->assertSame('72.0000', $line->quantity_base);
        $this->assertSame('72001.50', $line->line_subtotal);   // 3 x 24,000.50
        $this->assertSame('12780.27', $line->tax_amount);      // (72,001.50 - 1,000) x 18% = 12,780.27
        $this->assertSame('83781.77', $order->total);
        $this->assertSame('1000.00', $order->discount_total);
    }

    public function test_status_workflow_and_audit_trail(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $service = app(PurchaseOrderService::class);
        $order = $this->draft($company, $owner);

        // Draft cannot jump straight to approved.
        try {
            $service->approve($order, $owner);
            $this->fail('Draft approved directly.');
        } catch (InvalidDocumentStateException) {
        }

        $service->submit($order, $owner);
        $service->reject($order, $owner, 'Wrong supplier price');
        $this->assertSame(PurchaseOrderStatus::Draft, $order->fresh()->status);

        $service->submit($order, $owner);
        $approved = $service->approve($order, $owner);
        $this->assertSame(PurchaseOrderStatus::Approved, $approved->status);
        $this->assertSame($owner->id, $approved->approved_by);
        $this->assertNotNull($approved->approved_at);

        // Approved orders are no longer editable.
        $this->expectException(InvalidDocumentStateException::class);
        try {
            $service->updateDraft($order, ['supplier_id' => $order->supplier_id, 'branch_id' => $order->branch_id, 'order_date' => today()->toDateString()], [], $owner);
        } finally {
            $events = DocumentEvent::forCompany($company)->where('documentable_id', $order->id)->pluck('event')->all();
            $this->assertSame(['created', 'submitted', 'draft', 'submitted', 'approved'], $events);
        }
    }

    public function test_creating_and_approving_does_not_change_stock(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $order = $this->draft($company, $owner);
        app(PurchaseOrderService::class)->submit($order, $owner);
        app(PurchaseOrderService::class)->approve($order, $owner);

        $this->assertSame(0, StockMovement::forCompany($company)->count());
        $this->assertSame(0, StockBalance::forCompany($company)->count());
    }

    public function test_supplier_from_another_company_is_rejected(): void
    {
        $company = $this->createCompany();
        $other = $this->createCompany();
        $foreign = $this->makeSupplier($other, 'Foreign Supplier');
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);

        $this->expectException(BusinessRuleException::class);
        $this->draft($company, $owner, ['supplier_id' => $foreign->id]);
    }

    public function test_inactive_supplier_is_rejected(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $supplier = $this->makeSupplier($company);
        $supplier->forceFill(['is_active' => false])->save();

        $this->expectException(BusinessRuleException::class);
        $this->draft($company, $owner, ['supplier_id' => $supplier->id]);
    }

    public function test_cancellation_rules(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $service = app(PurchaseOrderService::class);

        $cancelled = $service->cancel($this->draft($company, $owner), $owner, 'Not needed');
        $this->assertSame(PurchaseOrderStatus::Cancelled, $cancelled->status);

        $this->expectException(InvalidDocumentStateException::class);
        $service->submit($cancelled, $owner);
    }

    public function test_store_keeper_can_create_but_not_approve(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $keeper = $this->addMember($company, SystemRole::StoreKeeper, [$this->headOffice($company)]);
        $this->actingAsTenant($keeper, $company);

        $order = $this->draft($company, $keeper);
        app(PurchaseOrderService::class)->submit($order, $keeper);

        $this->expectException(AuthorizationException::class);
        app(PurchaseOrderService::class)->approve($order, $keeper);
    }

    public function test_cashier_cannot_create_purchase_orders(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $cashier = $this->addMember($company, SystemRole::Cashier, [$this->headOffice($company)]);
        $this->actingAsTenant($cashier, $company);

        $this->expectException(AuthorizationException::class);
        $this->draft($company, $cashier);
    }

    public function test_purchase_order_for_unassigned_branch_is_rejected(): void
    {
        $company = $this->createCompany();
        $mine = $this->createBranch($company);
        $manager = $this->addMember($company, SystemRole::BranchManager, [$mine]);
        $this->actingAsTenant($manager, $company);

        $this->expectException(AuthorizationException::class);
        $this->draft($company, $manager, ['branch_id' => $this->headOffice($company)->id]);
    }
}
