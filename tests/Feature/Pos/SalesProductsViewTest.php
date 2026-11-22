<?php

namespace Tests\Feature\Pos;

use App\Enums\SystemRole;
use App\Livewire\Sales\SaleIndex;
use App\Services\Catalogue\PriceService;
use App\Services\Pos\SaleReturnService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesInventory;
use Tests\Concerns\CreatesPos;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Cashier Sales page: Products Sold + Transactions over the same scoped data. Reporting only, no shifts. */
class SalesProductsViewTest extends TestCase
{
    use CreatesInventory, CreatesPos, CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    private array $f = [];

    private function shop(): array
    {
        $company = $this->createCompany(['max_branches' => 5]);
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $hq = $this->headOffice($company);
        $soap = $this->makeProduct($company, $owner, ['name' => 'FAMILY MEDICATED SOAP', 'selling_price' => '2500']);
        $this->stock($company, $owner, $soap, '50');
        $device = $this->device($hq);
        $a = $this->addMember($company, SystemRole::Cashier, [$hq]);
        $b = $this->addMember($company, SystemRole::Cashier, [$hq]);

        return $this->f = compact('company', 'owner', 'hq', 'soap', 'device', 'a', 'b');
    }

    private function sellAs($user, string $qty, array $payments = [], $unit = null)
    {
        ['company' => $company, 'soap' => $soap, 'device' => $device] = $this->f;
        $this->actingAsTenant($user, $company);
        $unit ??= $this->baseUnitOf($soap);

        return $this->sell($company, $user, $device, [['product_id' => $soap->id, 'product_unit_id' => $unit->id, 'quantity' => $qty]],
            $payments ?: [['payment_method_id' => $this->method($company)->id, 'amount' => '1000000']]);
    }

    private function page($user, array $params = [])
    {
        $this->actingAsTenant($user, $this->f['company']);

        return Livewire::withQueryParams($params)->test(SaleIndex::class);
    }

    public function test_products_are_aggregated_across_sales_and_transactions_stay_separate(): void
    {
        ['a' => $a] = $this->shop();
        $s1 = $this->sellAs($a, '2', [['payment_method_id' => $this->method($this->f['company'])->id, 'amount' => '5000']]);
        $s2 = $this->sellAs($a, '2', [['payment_method_id' => $this->method($this->f['company'])->id, 'amount' => '5000']]);

        $page = $this->page($a)->assertSet('tab', 'products');   // default for cashiers
        $rows = $page->viewData('products');
        $this->assertCount(1, $rows);
        $this->assertSame(['FAMILY MEDICATED SOAP', 'Piece', '4', '10000.00', '2500.00', '0', '4', '10000.00'],
            [$rows[0]->product_name, $rows[0]->unit, $rows[0]->quantity, $rows[0]->gross, $rows[0]->min_price, $rows[0]->returned_quantity, $rows[0]->net_quantity, $rows[0]->net]);

        $summary = $page->viewData('summary');
        $this->assertSame([2, '10000.00', '10000.00', '10000.00'], [$summary['transactions'], $summary['gross_sales'], $summary['cash'], $summary['net_sales']]);
        $this->assertSame([['unit' => 'Piece', 'qty' => '4']], $summary['items_by_unit']);

        $page->call('setTab', 'transactions')->assertSee($s1->sale_number)->assertSee($s2->sale_number);
    }

    public function test_different_historical_prices_show_a_range_and_true_totals(): void
    {
        ['owner' => $owner, 'a' => $a, 'soap' => $soap] = $this->shop();
        $this->sellAs($a, '1');
        $this->actingAsTenant($owner, $this->f['company']);
        app(PriceService::class)->setPrice($soap, $this->baseUnitOf($soap), '3000', null, $owner);
        $this->sellAs($a, '1');

        $row = $this->page($a)->viewData('products')[0];
        $this->assertSame(['2', '5500.00', '2500.00', '3000.00', '2750.00'], [$row->quantity, $row->gross, $row->min_price, $row->max_price, $row->average_price]);
        $this->assertFalse($row->single_price);
        $this->page($a)->assertSee('2,500.00 – 3,000.00');
    }

    public function test_units_are_counted_separately(): void
    {
        ['owner' => $owner, 'a' => $a, 'soap' => $soap] = $this->shop();
        $this->actingAsTenant($owner, $this->f['company']);
        $pack = $this->packaging($soap, 'pack', '3');
        $this->sellAs($a, '2');
        $this->sellAs($a, '1', [], $pack);   // 1 pack = 3 pieces, derived price 7,500

        $page = $this->page($a);
        $this->assertSame(['Piece', 'Pack'], $page->viewData('products')->sortBy('unit', SORT_STRING | SORT_FLAG_CASE)->reverse()->pluck('unit')->values()->all());
        $this->assertSame('5', $page->viewData('summary')['items_sold']);   // base pieces: 2 + 3
    }

    public function test_partial_and_full_returns(): void
    {
        ['owner' => $owner, 'a' => $a, 'company' => $company] = $this->shop();
        $sale = $this->sellAs($a, '3');
        $item = $sale->items()->sole();
        $return = fn (string $qty) => app(SaleReturnService::class)->process($sale, $owner, (string) Str::uuid(), 'Changed mind', $this->method($company)->id,
            [['sale_item_id' => $item->id, 'quantity' => $qty, 'disposition' => 'restock']]);

        $this->actingAsTenant($owner, $company);
        $return('1');
        $row = $this->page($a)->viewData('products')[0];
        $this->assertSame(['3', '1', '2', '7500.00', '2500.00', '5000.00'], [$row->quantity, $row->returned_quantity, $row->net_quantity, $row->gross, $row->refunded, $row->net]);
        $summary = $this->page($a)->viewData('summary');
        $this->assertSame(['7500.00', '2500.00', '5000.00'], [$summary['gross_sales'], $summary['refunds'], $summary['net_sales']]);

        $this->actingAsTenant($owner, $company);
        $return('2');
        $row = $this->page($a)->viewData('products')[0];
        $this->assertSame(['0', '0.00'], [$row->net_quantity, $row->net]);
        $this->page($a, ['tab' => 'transactions'])->assertSee('Returned');
    }

    public function test_split_payments_and_change_are_counted_once(): void
    {
        ['a' => $a, 'company' => $company] = $this->shop();
        $this->sellAs($a, '2', [
            ['payment_method_id' => $this->method($company)->id, 'amount' => '3500'],
            ['payment_method_id' => $this->method($company, 'mobile_money')->id, 'amount' => '2000', 'reference' => 'MP1'],
        ]);
        $this->sellAs($a, '1', [['payment_method_id' => $this->method($company, 'card')->id, 'amount' => '2500']]);

        $s = $this->page($a)->viewData('summary');
        $this->assertSame(['7500.00', '3000.00', '2000.00', '2500.00', '500.00'], [$s['gross_sales'], $s['cash'], $s['mobile_money'], $s['card_bank'], $s['change_given']]);
    }

    public function test_cashier_sees_only_own_sales_even_with_tampered_parameters(): void
    {
        ['a' => $a, 'b' => $b] = $this->shop();
        $mine = $this->sellAs($a, '1');
        $theirs = $this->sellAs($b, '5');

        $page = $this->page($a, ['cashier' => (string) $b->id, 'tab' => 'transactions', 'period' => 'all']);
        $page->assertSee($mine->sale_number)->assertDontSee($theirs->sale_number);
        $this->assertSame('2500.00', $page->viewData('summary')['gross_sales']);
        $this->assertSame('1', $this->page($a, ['cashier' => (string) $b->id])->viewData('products')[0]->quantity);

        // Receipts: own reprint allowed, another cashier's forbidden.
        $this->actingAs($a)->get(route('sales.receipt', $mine))->assertOk();
        $this->actingAs($a)->get(route('sales.receipt', $theirs))->assertForbidden();
        $this->actingAs($a)->get(route('sales.show', $theirs))->assertForbidden();
    }

    public function test_branch_and_company_isolation(): void
    {
        ['company' => $company, 'owner' => $owner, 'a' => $a] = $this->shop();
        $hqSale = $this->sellAs($a, '1');

        $this->actingAsTenant($owner, $company);
        $other = $this->createBranch($company, ['name' => 'Mbezi']);
        $manager = $this->addMember($company, SystemRole::BranchManager, [$other]);

        $managerPage = $this->page($manager, ['tab' => 'transactions', 'branch' => $this->f['hq']->uuid]);
        $managerPage->assertDontSee($hqSale->sale_number);
        $this->assertSame(0, $managerPage->viewData('summary')['transactions']);
        $this->assertSame('0.00', $this->page($manager)->viewData('summary')['gross_sales']);

        $this->page($owner, ['tab' => 'transactions'])->assertSee($hqSale->sale_number);

        app(TenantContext::class)->clear();
        $foreign = $this->createCompany();
        $stranger = $this->createOwner($foreign);
        $this->actingAs($stranger)->get(route('sales.index', ['tab' => 'transactions', 'period' => 'all']))->assertOk()->assertDontSee($hqSale->sale_number);
        $this->actingAs($stranger)->get(route('sales.receipt', $hqSale))->assertNotFound();
    }

    public function test_periods_follow_the_company_timezone(): void
    {
        // 21:30 UTC on the 20th = 00:30 on the 21st in Dar es Salaam (UTC+3).
        $this->travelTo(Carbon::parse('2026-11-20 21:30:00', 'UTC'));
        ['a' => $a] = $this->shop();   // subscription active from "now"
        $this->sellAs($a, '1');

        $this->travelTo(Carbon::parse('2026-11-21 12:00:00', 'UTC'));
        $this->assertSame(1, $this->page($a)->viewData('summary')['transactions'], 'Today (21st, EAT)');

        $this->travelTo(Carbon::parse('2026-11-22 08:00:00', 'UTC'));
        $count = fn (array $p) => $this->page($a, $p)->viewData('summary')['transactions'];
        $this->assertSame(0, $count(['period' => 'today']));
        $this->assertSame(1, $count(['period' => 'yesterday']));
        $this->assertSame(1, $count(['period' => 'this_week']));
        $this->assertSame(1, $count(['period' => 'this_month']));
        $this->assertSame(0, $count(['period' => 'custom', 'from' => '2026-11-20', 'to' => '2026-11-20']));
        $this->assertSame(1, $count(['period' => 'custom', 'from' => '2026-11-21', 'to' => '2026-11-21']));
        $this->assertSame(1, $count(['period' => 'all']));
    }

    public function test_mobile_layout_and_localisation(): void
    {
        ['a' => $a] = $this->shop();
        $this->sellAs($a, '2');

        $html = $this->actingAs($a)->get(route('sales.index'))->assertOk()->getContent();
        $this->assertStringContainsString('space-y-2 lg:hidden', $html, 'Products as cards on phones');
        $this->assertStringContainsString('hidden lg:block', $html, 'Wide table only on large screens');
        $this->assertStringContainsString(route('pos'), $html, 'Open POS button');

        $pos = $this->actingAs($a)->get(route('pos'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<a href="'.preg_quote(route('sales.index'), '/').'" data-my-sales-link class="inline-flex/', $pos, 'My Sales link visible at every width');
        $this->actingAs($a)->get('/my-sales')->assertRedirect('/sales');

        $a->forceFill(['locale' => 'sw'])->save();
        $this->actingAs($a->fresh())->get(route('sales.index'))->assertSee('Mauzo Yangu')->assertSee('Bidhaa Zilizouzwa');
    }
}
