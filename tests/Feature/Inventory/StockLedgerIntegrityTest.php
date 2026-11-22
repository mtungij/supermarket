<?php

namespace Tests\Feature\Inventory;

use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Support\Decimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\CreatesInventory;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class StockLedgerIntegrityTest extends TestCase
{
    use CreatesInventory, CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    private function received(): array
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $juice = $this->makeProduct($company, $owner);
        $piece = $this->baseUnitOf($juice);
        $order = $this->approvedOrder($company, $this->headOffice($company), $this->makeSupplier($company), [[$juice, $piece, '10', '1000']], $owner);
        $this->receiveGoods($order, [['purchase_order_line_id' => $order->lines()->sole()->id, 'product_unit_id' => $piece->id, 'quantity' => '10']], $owner);

        return [$company, $juice];
    }

    public function test_movements_are_immutable(): void
    {
        [$company] = $this->received();
        $movement = StockMovement::forCompany($company)->sole();

        try {
            $movement->forceFill(['quantity' => '999'])->save();
            $this->fail('Movement updated.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $movement->delete();
    }

    public function test_balances_equal_the_sum_of_movements(): void
    {
        [$company, $juice] = $this->received();

        $sum = StockMovement::forCompany($company)->where('product_id', $juice->id)->get()
            ->reduce(fn ($c, $m) => Decimal::add($c, (string) $m->quantity), '0');
        $balance = StockBalance::forCompany($company)->where('product_id', $juice->id)->sum('quantity');

        $this->assertSame(0, Decimal::cmp($sum, (string) $balance));
    }

    public function test_stock_balances_cannot_be_mass_assigned(): void
    {
        $this->assertSame(['*'], (new StockBalance)->getGuarded());
        $this->assertSame(['*'], (new StockMovement)->getGuarded());
    }
}
