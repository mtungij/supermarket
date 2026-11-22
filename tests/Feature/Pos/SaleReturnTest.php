<?php

namespace Tests\Feature\Pos;

use App\Enums\LocationType;
use App\Enums\SystemRole;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Services\Pos\CheckoutService;
use App\Services\Pos\SaleReturnService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesInventory;
use Tests\Concerns\CreatesPos;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class SaleReturnTest extends TestCase
{
    use CreatesInventory, CreatesPos, CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    /** Sale of 3 soaps @ 2,500 (7,500) with COGS 1,000 each. */
    private function sold(): array
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $soap = $this->makeProduct($company, $owner, ['name' => 'Soap', 'selling_price' => '2500']);
        $this->stock($company, $owner, $soap, '10');
        $device = $this->device($this->headOffice($company));
        $sale = $this->sell($company, $owner, $device, [['product_id' => $soap->id, 'product_unit_id' => $this->baseUnitOf($soap)->id, 'quantity' => '3']],
            [['payment_method_id' => $this->method($company)->id, 'amount' => '7500']]);

        return [$company, $owner, $sale, $soap, $sale->items()->sole(), $device];
    }

    private function refund($sale, $actor, $item, string $qty, string $disposition = 'restock', ?string $key = null, string $method = 'cash')
    {
        return app(SaleReturnService::class)->process($sale, $actor, $key ?? (string) Str::uuid(), 'Customer changed mind',
            $this->method(Company::find($sale->company_id), $method)->id,
            [['sale_item_id' => $item->id, 'quantity' => $qty, 'disposition' => $disposition]]);
    }

    public function test_partial_returns_refund_and_restock_at_original_cost(): void
    {
        [$company, $owner, $sale, $soap, $item] = $this->sold();
        $floor = $this->location($this->headOffice($company), LocationType::SalesFloor);

        $first = $this->refund($sale, $owner, $item, '1');
        $this->assertSame(['RET-000001', '2500.00', '381.36', '1000.0000'], [$first->return_number, $first->refund_total, $first->tax_total, $first->cost_total]);
        $this->assertSame('8.0000', $this->onHand($floor, $soap));

        $movement = StockMovement::forCompany($company)->where('type', 'sale_return')->sole();
        $this->assertSame(['1.0000', '1000.0000'], [$movement->quantity, $movement->unit_cost]);

        // Final return of the line refunds the exact remainder.
        $second = $this->refund($sale, $owner, $item, '2');
        $this->assertSame('5000.00', $second->refund_total);
        $this->assertSame('7500.00', (string) SaleReturn::forCompany($company)->sum('refund_total'));
        $this->assertSame('returned', $sale->fresh()->returnStatus());
        $this->assertSame('7500.00', $sale->fresh()->items()->sole()->line_total, 'Original sale line is never modified.');
    }

    public function test_cannot_return_more_than_sold_or_twice(): void
    {
        [, $owner, $sale, , $item] = $this->sold();
        $key = (string) Str::uuid();

        $this->refund($sale, $owner, $item, '2', key: $key);
        $again = $this->refund($sale, $owner, $item, '2', key: $key); // same request repeated
        $this->assertSame(1, SaleReturn::query()->count());
        $this->assertSame('5000.00', $again->refund_total);

        $this->expectException(BusinessRuleException::class);
        $this->refund($sale, $owner, $item, '2'); // only 1 left
    }

    public function test_damaged_returns_go_to_quarantine_not_sellable_stock(): void
    {
        [$company, $owner, $sale, $soap, $item] = $this->sold();
        $hq = $this->headOffice($company);

        $this->refund($sale, $owner, $item, '1', 'damaged');

        $this->assertSame('7.0000', $this->onHand($this->location($hq, LocationType::SalesFloor), $soap));
        $this->assertSame('1.0000', $this->onHand($this->location($hq, LocationType::Quarantine), $soap));
        $this->assertFalse($this->location($hq, LocationType::Quarantine)->is_sellable);
    }

    public function test_returns_require_refund_permission(): void
    {
        [$company, , $sale, , $item] = $this->sold();
        $cashier = $this->addMember($company, SystemRole::Cashier, [$this->headOffice($company)]);
        $this->actingAsTenant($cashier, $company);

        $this->expectException(AuthorizationException::class);
        $this->refund($sale, $cashier, $item, '1');
    }

    public function test_exchange_uses_return_credit_once(): void
    {
        [$company, $owner, $sale, $soap, $item, $device] = $this->sold();
        $return = $this->refund($sale, $owner, $item, '1', method: 'exchange_credit'); // 2,500 credit

        $checkout = fn () => app(CheckoutService::class)->checkout($company, $this->headOffice($company), $device, $owner, (string) Str::uuid(),
            [['product_id' => $soap->id, 'product_unit_id' => $this->baseUnitOf($soap)->id, 'quantity' => '2']],
            [['payment_method_id' => $this->method($company, 'exchange_credit')->id, 'amount' => '2500'], ['payment_method_id' => $this->method($company)->id, 'amount' => '2500']],
            null, null, $return->id);

        $replacement = $checkout();
        $this->assertSame($return->id, $replacement->exchange_return_id);
        $this->assertSame('5000.00', $replacement->total);

        $this->expectException(BusinessRuleException::class); // credit already used
        $checkout();
    }
}
