<?php

namespace Tests\Feature\Pos;

use App\Enums\LocationType;
use App\Enums\SystemRole;
use App\Livewire\Pos\Terminal;
use App\Models\Sale;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesInventory;
use Tests\Concerns\CreatesPos;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class PosTerminalTest extends TestCase
{
    use CreatesInventory, CreatesPos, CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    private function shop(): array
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $soap = $this->makeProduct($company, $owner, ['name' => 'FAMILY MEDICATED SOAP', 'selling_price' => '2500', 'barcode' => '6203013430060']);
        $this->stock($company, $owner, $soap, '6');
        $this->stock($company, $owner, $soap, '14', type: LocationType::ReceivingArea);
        $device = $this->device($this->headOffice($company));
        $cashier = $this->addMember($company, SystemRole::Cashier, [$this->headOffice($company)]);
        $this->actingAsTenant($cashier, $company);
        session()->put('pos.device.'.$this->headOffice($company)->id, $device->uuid);

        return [$company, $owner, $cashier, $device, $soap];
    }

    public function test_cashier_logs_in_straight_to_the_pos_without_a_shift(): void
    {
        [, , $cashier] = $this->shop();

        $this->actingAs($cashier)->get('/dashboard')->assertRedirect(route('pos'));
        $this->actingAs($cashier)->get(route('pos'))->assertOk()
            ->assertSee('data-barcode-scan', false)
            ->assertSee('x-on:keydown.enter.prevent="submit()"', false)
            ->assertDontSee('shift', false);
    }

    public function test_scan_with_enter_then_repeat_scan_increments(): void
    {
        [, , , , $soap] = $this->shop();

        $pos = Livewire::test(Terminal::class)
            ->call('scan', '6203013430060')   // what the client queue sends on Enter
            ->assertHasNoErrors()
            ->call('scan', '6203013430060');

        $line = array_values($pos->get('cart'))[0];
        $this->assertCount(1, $pos->get('cart'));
        $this->assertSame(['product_id' => $soap->id, 'quantity' => '2', 'barcode' => '6203013430060'], ['product_id' => $line['product_id'], 'quantity' => $line['quantity'], 'barcode' => $line['barcode']]);
        $summary = $pos->viewData('summary');
        $this->assertSame('5000.00', $summary['totals']['total']);
        $this->assertSame('762.71', $summary['totals']['tax']);
        $pos->assertSee('Piece')->assertSee('2,500.00');
    }

    public function test_unknown_foreign_and_inactive_products_cannot_be_scanned(): void
    {
        [$company, $owner, $cashier, , $soap] = $this->shop();
        $other = app(TenantContext::class)->withoutTenancy(fn () => $this->createCompany());
        app(TenantContext::class)->withoutTenancy(fn () => $this->makeProduct($other, $owner, ['barcode' => '6001234567899']));
        $this->actingAsTenant($cashier, $company);

        Livewire::test(Terminal::class)->call('scan', '9999999999999')->assertHasErrors('scan')->assertSet('cart', []);
        Livewire::test(Terminal::class)->call('scan', '6001234567899')->assertHasErrors('scan')->assertSet('cart', []);

        $soap->forceFill(['is_active' => false])->save();
        Livewire::test(Terminal::class)->call('scan', '6203013430060')->assertHasErrors('scan')->assertSet('cart', []);
    }

    public function test_receiving_area_stock_is_not_offered(): void
    {
        $this->shop();

        $pos = Livewire::test(Terminal::class);
        foreach (range(1, 6) as $ignored) {
            $pos->call('scan', '6203013430060');
        }
        $pos->assertHasNoErrors()->call('scan', '6203013430060')->assertHasErrors('scan'); // 7th: only 6 on the Sales Floor
        $this->assertSame('6', array_values($pos->get('cart'))[0]['quantity']);
    }

    public function test_complete_sale_split_payment_change_and_double_submit(): void
    {
        [$company, , $cashier, $device, $soap] = $this->shop();

        $pos = Livewire::test(Terminal::class)
            ->call('scan', '6203013430060')->call('scan', '6203013430060')
            ->call('startPayment')
            ->assertSet('payments.0.amount', '5000');

        $mobile = $this->method($company, 'mobile_money');
        $pos->set('payments.0.amount', '3500')       // cash
            ->call('addPayment', $mobile->uuid)
            ->set('payments.1.amount', '2000')
            ->set('payments.1.reference', 'MPESA-1');
        $key = $pos->get('checkoutKey');

        $pos->call('checkout')->assertHasNoErrors()->assertDispatched('sale-completed');
        $sale = Sale::forCompany($company)->sole();
        $this->assertSame(['5000.00', '5500.00', '500.00', $cashier->id, $device->id], [$sale->total, $sale->tendered_total, $sale->change_total, $sale->cashier_id, $sale->pos_device_id]);
        $this->assertNotSame($key, $pos->get('checkoutKey'), 'A new cart gets a new idempotency key.');
        $this->assertSame([], $pos->get('cart'));
        $this->assertSame('4.0000', $this->onHand($this->location($this->headOffice($company), LocationType::SalesFloor), $soap));
        $this->assertSame('14.0000', $this->onHand($this->location($this->headOffice($company), LocationType::ReceivingArea), $soap));
    }

    public function test_double_clicking_complete_creates_one_sale(): void
    {
        [$company] = $this->shop();

        // Livewire processes a component's requests in order: the second click
        // arrives with the post-sale state (empty cart, new key) and is refused.
        // A replay of the SAME key is answered by CheckoutService idempotency
        // (CheckoutServiceTest::test_duplicate_checkout_requests_create_one_sale).
        $pos = Livewire::test(Terminal::class)->call('scan', '6203013430060')->call('startPayment');
        $pos->call('checkout')->assertHasNoErrors();
        $pos->call('checkout');

        $this->assertSame(1, Sale::forCompany($company)->count());
    }

    public function test_underpayment_is_rejected_and_discount_tampering_ignored(): void
    {
        [$company] = $this->shop();

        $pos = Livewire::test(Terminal::class)->call('scan', '6203013430060');
        $key = array_key_first($pos->get('cart'));
        $pos->set("cart.{$key}.discount", '2000')     // cashier has no sales.discount
            ->assertSet("cart.{$key}.discount", '0')
            ->call('startPayment')
            ->set('payments.0.amount', '2000')
            ->call('checkout')
            ->assertHasErrors('payments');

        $this->assertSame(0, Sale::forCompany($company)->count());
    }

    public function test_register_is_required_and_branch_scoped(): void
    {
        [$company, , $cashier] = $this->shop();
        session()->forget('pos.device.'.$this->headOffice($company)->id);
        $foreignDevice = $this->device($this->createBranch($company), 'Other branch till');
        $this->actingAsTenant($cashier, $company);

        Livewire::test(Terminal::class)->assertSee('Select your register')->call('selectDevice', $foreignDevice->uuid)->assertForbidden();
    }

    public function test_users_without_pos_access_are_refused(): void
    {
        [$company] = $this->shop();
        $keeper = $this->addMember($company, SystemRole::StoreKeeper, [$this->headOffice($company)]);

        $this->actingAs($keeper)->get(route('pos'))->assertForbidden();
    }
}
