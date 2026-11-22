<?php

namespace Tests\Feature\Pos;

use App\Enums\LocationType;
use App\Enums\SystemRole;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\StockMovement;
use App\Services\Catalogue\PriceService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesInventory;
use Tests\Concerns\CreatesPos;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class CheckoutServiceTest extends TestCase
{
    use CreatesInventory, CreatesPos, CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    /** Soap: 2,500 incl. 18% VAT; 6 on Sales Floor, 14 in Receiving Area @ 1,000. */
    private function shop(): array
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $soap = $this->makeProduct($company, $owner, ['name' => 'FAMILY MEDICATED SOAP', 'selling_price' => '2500', 'purchase_cost' => '1000', 'barcode' => '6203013430060']);
        $this->stock($company, $owner, $soap, '6');
        $this->stock($company, $owner, $soap, '14', type: LocationType::ReceivingArea);
        $cashier = $this->addMember($company, SystemRole::Cashier, [$this->headOffice($company)]);
        $device = $this->device($this->headOffice($company));
        $this->actingAsTenant($cashier, $company);

        return [$company, $owner, $cashier, $device, $soap, $this->baseUnitOf($soap)];
    }

    public function test_acceptance_sale_of_two_soaps(): void
    {
        [$company, , $cashier, $device, $soap, $piece] = $this->shop();
        $hq = $this->headOffice($company);

        $sale = $this->sell($company, $cashier, $device,
            [['product_id' => $soap->id, 'product_unit_id' => $piece->id, 'quantity' => '2', 'barcode' => '6203013430060']],
            [['payment_method_id' => $this->method($company)->id, 'amount' => '5000']]);

        $this->assertSame('S-000001', $sale->sale_number);
        $this->assertSame('5000.00', $sale->total);
        $this->assertSame('762.71', $sale->tax_total);    // 5,000 x 18/118, VAT included
        $this->assertSame('4237.29', $sale->net_total);
        $this->assertSame('2000.0000', $sale->cost_total); // COGS 2 x 1,000
        $this->assertSame([$cashier->id, $device->id, $hq->id], [$sale->cashier_id, $sale->pos_device_id, $sale->branch_id]);

        $this->assertSame('4.0000', $this->onHand($this->location($hq, LocationType::SalesFloor), $soap));
        $this->assertSame('14.0000', $this->onHand($this->location($hq, LocationType::ReceivingArea), $soap), 'Receiving Area untouched');

        $movement = StockMovement::forCompany($company)->where('type', 'sale')->sole();
        $this->assertSame('-2.0000', $movement->quantity);
        $this->assertSame('1000.0000', $movement->unit_cost);
        $this->assertSame($sale->sale_number, $movement->reference);

        $item = $sale->items()->sole();
        $this->assertSame(['FAMILY MEDICATED SOAP', 'Piece', '2500.00', '6203013430060'], [$item->product_name, $item->unit_name, $item->unit_price, $item->barcode]);
    }

    public function test_receiving_area_stock_is_never_sold(): void
    {
        [$company, , $cashier, $device, $soap, $piece] = $this->shop();

        $this->expectException(InsufficientStockException::class);
        $this->sell($company, $cashier, $device, [['product_id' => $soap->id, 'product_unit_id' => $piece->id, 'quantity' => '7']],
            [['payment_method_id' => $this->method($company)->id, 'amount' => '17500']]);
    }

    public function test_split_payment_and_cash_change(): void
    {
        [$company, , $cashier, $device, $soap, $piece] = $this->shop();

        $sale = $this->sell($company, $cashier, $device, [['product_id' => $soap->id, 'product_unit_id' => $piece->id, 'quantity' => '2']], [
            ['payment_method_id' => $this->method($company, 'mobile_money')->id, 'amount' => '2000', 'reference' => 'MP123'],
            ['payment_method_id' => $this->method($company)->id, 'amount' => '5000'],
        ]);

        $this->assertSame(['5000.00', '7000.00', '2000.00'], [$sale->paid_total, $sale->tendered_total, $sale->change_total]);
        $cash = SalePayment::forCompany($company)->where('sale_id', $sale->id)->where('method_type', 'cash')->sole();
        $this->assertSame(['3000.00', '5000.00', '2000.00'], [$cash->amount, $cash->tendered, $cash->change_given]);
        $this->assertSame('5000.00', (string) SalePayment::forCompany($company)->where('sale_id', $sale->id)->sum('amount'), 'Change is not revenue.');
    }

    public function test_payment_validation(): void
    {
        [$company, , $cashier, $device, $soap, $piece] = $this->shop();
        $line = [['product_id' => $soap->id, 'product_unit_id' => $piece->id, 'quantity' => '2']];
        $cases = [
            [['payment_method_id' => $this->method($company)->id, 'amount' => '4999']],                                            // underpaid
            [['payment_method_id' => $this->method($company)->id, 'amount' => '0']],                                               // zero
            [['payment_method_id' => $this->method($company)->id, 'amount' => '-5000']],                                           // negative
            [['payment_method_id' => $this->method($company, 'card')->id, 'amount' => '6000']],                                    // card overpay
            [['payment_method_id' => $this->method($company, 'mobile_money')->id, 'amount' => '5000']],                            // missing reference
            [],
        ];

        foreach ($cases as $payments) {
            try {
                $this->sell($company, $cashier, $device, $line, $payments);
                $this->fail('Invalid payment accepted: '.json_encode($payments));
            } catch (BusinessRuleException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, Sale::forCompany($company)->count());
        $this->assertSame('6.0000', $this->onHand($this->location($this->headOffice($company), LocationType::SalesFloor), $soap));
    }

    public function test_duplicate_checkout_requests_create_one_sale(): void
    {
        [$company, , $cashier, $device, $soap, $piece] = $this->shop();
        $key = (string) Str::uuid();
        $args = [[['product_id' => $soap->id, 'product_unit_id' => $piece->id, 'quantity' => '1']], [['payment_method_id' => $this->method($company)->id, 'amount' => '2500']]];

        $first = $this->sell($company, $cashier, $device, ...$args, key: $key);
        $second = $this->sell($company, $cashier, $device, ...$args, key: $key);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Sale::forCompany($company)->count());
        $this->assertSame('5.0000', $this->onHand($this->location($this->headOffice($company), LocationType::SalesFloor), $soap));
    }

    public function test_unit_conversion_unit_prices_and_tax_exclusive_companies(): void
    {
        [$company, $owner, $cashier, $device, $soap] = $this->shop();
        $this->actingAsTenant($owner, $company);
        $pack = $this->packaging($soap, 'pack', '3');
        app(PriceService::class)->setPrice($soap, $pack, '7000', null, $owner);
        $company->forceFill(['settings' => array_merge($company->settings, ['prices_include_tax' => false])])->save();
        $this->actingAsTenant($cashier, $company);

        $sale = $this->sell($company->fresh(), $cashier, $device, [['product_id' => $soap->id, 'product_unit_id' => $pack->id, 'quantity' => '2']],
            [['payment_method_id' => $this->method($company)->id, 'amount' => '20000']]);

        $item = $sale->items()->sole();
        $this->assertSame(['7000.00', '6.0000', '3.000000'], [$item->unit_price, $item->quantity_base, $item->conversion_factor]);
        $this->assertSame(['14000.00', '2520.00', '16520.00'], [$sale->net_total, $sale->tax_total, $sale->total]); // + 18% VAT on top
        $this->assertSame('0.0000', $this->onHand($this->location($this->headOffice($company), LocationType::SalesFloor), $soap));
    }

    public function test_discounts_require_permission_and_are_bounded(): void
    {
        [$company, , $cashier, $device, $soap, $piece] = $this->shop();
        $line = fn ($d) => [['product_id' => $soap->id, 'product_unit_id' => $piece->id, 'quantity' => '2', 'discount' => $d]];
        $cash = [['payment_method_id' => $this->method($company)->id, 'amount' => '5000']];

        try {
            $this->sell($company, $cashier, $device, $line('500'), $cash);
            $this->fail('Cashier gave a discount.');
        } catch (AuthorizationException) {
        }

        $supervisor = $this->addMember($company, SystemRole::Supervisor, [$this->headOffice($company)]);
        $this->actingAsTenant($supervisor, $company);
        $sale = $this->sell($company, $supervisor, $device, $line('500'), $cash);
        $this->assertSame(['5000.00', '500.00', '4500.00', '686.44'], [$sale->subtotal, $sale->discount_total, $sale->total, $sale->tax_total]);

        $this->expectException(BusinessRuleException::class);
        $this->sell($company, $supervisor, $device, $line('6000'), $cash);
    }

    public function test_device_rules(): void
    {
        [$company, , $cashier, , $soap, $piece] = $this->shop();
        $args = [[['product_id' => $soap->id, 'product_unit_id' => $piece->id, 'quantity' => '1']], [['payment_method_id' => $this->method($company)->id, 'amount' => '2500']]];

        try {
            $this->sell($company, $cashier, null, ...$args);
            $this->fail('Sold without a registered device.');
        } catch (BusinessRuleException) {
        }

        $otherBranchDevice = $this->device($this->createBranch($company));
        try {
            $this->sell($company, $cashier, $otherBranchDevice, ...$args);
            $this->fail('Sold on another branch register.');
        } catch (BusinessRuleException) {
        }

        $revoked = $this->device($this->headOffice($company), 'Old');
        $revoked->forceFill(['status' => 'revoked'])->save();
        $this->expectException(BusinessRuleException::class);
        $this->sell($company, $cashier, $revoked, ...$args);
    }

    public function test_fefo_allocation_skips_expired_lots(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $milk = $this->makeProduct($company, $owner, ['name' => 'Milk', 'track_batches' => true, 'track_expiry' => true, 'selling_price' => '1500']);
        $this->stock($company, $owner, $milk, '5', line: ['batch_number' => 'LATE', 'expires_on' => today()->addDays(20)->toDateString()]);
        $this->stock($company, $owner, $milk, '3', line: ['batch_number' => 'SOON', 'expires_on' => today()->addDays(2)->toDateString()]);
        $this->stock($company, $owner, $milk, '4', line: ['batch_number' => 'OLD', 'expires_on' => today()->addDay()->toDateString()]);
        $this->travel(2)->days(); // OLD is now expired
        $device = $this->device($this->headOffice($company));

        $sale = $this->sell($company, $owner, $device, [['product_id' => $milk->id, 'product_unit_id' => $this->baseUnitOf($milk)->id, 'quantity' => '4']],
            [['payment_method_id' => $this->method($company)->id, 'amount' => '6000']]);

        $lots = $sale->items()->sole()->allocations()->with('batch')->get()->map(fn ($a) => [$a->batch->batch_number, $a->quantity_base])->all();
        $this->assertSame([['SOON', '3.0000'], ['LATE', '1.0000']], $lots);

        $this->expectException(InsufficientStockException::class); // 4 left sellable; OLD (4) is expired
        $this->sell($company, $owner, $device, [['product_id' => $milk->id, 'product_unit_id' => $this->baseUnitOf($milk)->id, 'quantity' => '5']],
            [['payment_method_id' => $this->method($company)->id, 'amount' => '7500']]);
    }

    public function test_inactive_products_and_foreign_ids_are_refused(): void
    {
        [$company, $owner, $cashier, $device, $soap, $piece] = $this->shop();
        $cash = [['payment_method_id' => $this->method($company)->id, 'amount' => '2500']];

        $soap->forceFill(['is_active' => false])->save();
        try {
            $this->sell($company, $cashier, $device, [['product_id' => $soap->id, 'product_unit_id' => $piece->id, 'quantity' => '1']], $cash);
            $this->fail('Inactive product sold.');
        } catch (BusinessRuleException) {
        }
        $soap->forceFill(['is_active' => true])->save();

        $other = app(TenantContext::class)->withoutTenancy(fn () => $this->createCompany());
        $foreignProduct = app(TenantContext::class)->withoutTenancy(fn () => $this->makeProduct($other, $owner));
        try {
            $this->sell($company, $cashier, $device, [['product_id' => $foreignProduct->id, 'product_unit_id' => $this->baseUnitOf($foreignProduct)->id, 'quantity' => '1']], $cash);
            $this->fail('Foreign product sold.');
        } catch (BusinessRuleException) {
        }

        $this->expectException(BusinessRuleException::class);
        $this->sell($company, $cashier, $device, [['product_id' => $soap->id, 'product_unit_id' => $piece->id, 'quantity' => '1']],
            [['payment_method_id' => $this->method($other)->id, 'amount' => '2500']]);
    }
}
