<?php

namespace Tests\Feature\Inventory;

use App\Enums\LocationType;
use App\Livewire\Catalogue\ProductForm;
use App\Livewire\Purchasing\GoodsReceiptForm;
use App\Livewire\Purchasing\PurchaseOrderForm;
use App\Models\PurchaseOrder;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesInventory;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class SubscriptionAndWorkflowUiTest extends TestCase
{
    use CreatesInventory, CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    public function test_full_purchase_to_stock_flow_through_the_ui(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $juice = $this->makeProduct($company, $owner, ['name' => 'Juice', 'purchase_cost' => '1000', 'barcode' => '6001234567899']);
        $carton = $this->packaging($juice, 'ctn', '24');
        $supplier = $this->makeSupplier($company);

        // 1. Draft + submit via the form (product added by scanning).
        Livewire::test(PurchaseOrderForm::class)
            ->set('supplier', $supplier->uuid)
            ->set('scan', '6001234567899')->call('scanBarcode')
            ->set('lines.0.product_unit_id', $carton->id)
            ->set('lines.0.quantity', '5')
            ->set('lines.0.unit_cost', '24000')
            ->call('save', true)
            ->assertHasNoErrors()
            ->assertRedirect();

        // 2. "Send PO" by an approver (the owner) approves immediately.
        $order = PurchaseOrder::forCompany($company)->sole();
        $this->assertSame('approved', $order->status->value);
        $this->assertSame('141600.00', $order->total); // 5 x 24,000 + 18% VAT
        $this->assertSame(['created', 'submitted', 'approved'], $order->events()->pluck('event')->all());

        // 3. Receive 3 of 5 cartons (quantities start empty; Receive All would fill 5).
        $this->actingAsTenant($owner, $company);
        Livewire::test(GoodsReceiptForm::class, ['order' => $order->fresh()])
            ->assertSet('lines.0.quantity', '')
            ->assertSet('lines.0.unit_cost', '24000')
            ->call('receiveAll')
            ->assertSet('lines.0.quantity', '5')
            ->set('lines.0.quantity', '3')
            ->set('invoiceNumber', 'INV-77')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertSame('72.0000', $this->onHand($this->location($this->headOffice($company), LocationType::SalesFloor), $juice));   // default destination
        $this->assertSame('partially_received', $order->fresh()->status->value);
        $this->actingAs($owner)->get(route('inventory.stock'))->assertSee('72 Piece');
    }

    public function test_expired_subscription_makes_inventory_read_only(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $juice = $this->makeProduct($company, $owner, ['name' => 'Juice']);
        Subscription::forCompany($company)->update(['status' => 'expired', 'ends_at' => now()->subDay()]);

        $this->actingAs($owner)->get(route('catalogue.products.index'))->assertOk()->assertSee('Juice');
        $this->actingAs($owner)->get(route('inventory.stock'))->assertOk();

        $this->actingAsTenant($owner, $company);
        Livewire::test(ProductForm::class)->set('name', 'New')->set('category', $this->defaultCategory($company)->uuid)->set('sellingPrice', '1000')->call('save')->assertHasErrors('name');

        Livewire::test(PurchaseOrderForm::class)
            ->set('supplier', $this->makeSupplier($company)->uuid)
            ->set('lines', [['product_id' => $juice->id, 'product_unit_id' => $this->baseUnitOf($juice)->id, 'quantity' => '1', 'unit_cost' => '1', 'discount_amount' => '0', 'tax_rate' => '0']])
            ->call('save')->assertHasErrors('supplier_id');
        $this->assertSame(0, PurchaseOrder::forCompany($company)->count());
    }
}
