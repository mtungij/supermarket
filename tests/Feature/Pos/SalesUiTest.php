<?php

namespace Tests\Feature\Pos;

use App\Enums\SystemRole;
use App\Livewire\Company\AddStaffMember;
use App\Livewire\Customers\CustomerIndex;
use App\Livewire\Sales\SaleShow;
use App\Models\Customer;
use App\Models\SaleReturn;
use App\Services\Pos\CustomerService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesInventory;
use Tests\Concerns\CreatesPos;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class SalesUiTest extends TestCase
{
    use CreatesInventory, CreatesPos, CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    private function soldBy(): array
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $soap = $this->makeProduct($company, $owner, ['name' => 'FAMILY MEDICATED SOAP WITH A VERY LONG NAME FOR THERMAL PAPER', 'selling_price' => '2500']);
        $this->stock($company, $owner, $soap, '10');
        $device = $this->device($this->headOffice($company), 'Till 1', 58);
        $cashierA = $this->addMember($company, SystemRole::Cashier, [$this->headOffice($company)]);
        $cashierB = $this->addMember($company, SystemRole::Cashier, [$this->headOffice($company)]);
        $line = [['product_id' => $soap->id, 'product_unit_id' => $this->baseUnitOf($soap)->id, 'quantity' => '2']];
        $cash = [['payment_method_id' => $this->method($company)->id, 'amount' => '10000']];

        $this->actingAsTenant($cashierA, $company);
        $saleA = $this->sell($company, $cashierA, $device, $line, $cash);
        $this->actingAsTenant($cashierB, $company);
        $saleB = $this->sell($company, $cashierB, $device, $line, $cash);

        return compact('company', 'owner', 'soap', 'device', 'cashierA', 'cashierB', 'saleA', 'saleB');
    }

    public function test_receipts_print_at_58_and_80_mm(): void
    {
        ['owner' => $owner, 'saleA' => $sale] = $this->soldBy();

        $this->actingAs($owner)->get(route('sales.receipt', $sale))->assertOk()
            ->assertSee('size: 58mm auto', false)                    // device setting
            ->assertSee($sale->sale_number)->assertSee('FAMILY MEDICATED SOAP WITH A VERY LONG NAME FOR THERMAL PAPER')
            ->assertSee('5,000.00')->assertSee('10,000.00')->assertSee('Change')
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertSee('color-scheme: light', false);

        $this->actingAs($owner)->get(route('sales.receipt', [$sale, 'width' => 80, 'autoprint' => 1]))->assertOk()
            ->assertSee('size: 80mm auto', false)->assertSee('window.print()', false);
    }

    public function test_sales_history_permissions(): void
    {
        ['company' => $company, 'owner' => $owner, 'cashierA' => $a, 'saleA' => $saleA, 'saleB' => $saleB] = $this->soldBy();

        $this->actingAs($owner)->get(route('sales.index'))->assertOk()->assertSee($saleA->sale_number)->assertSee($saleB->sale_number);

        // Cashier A only sees own sales and cannot open B's sale or receipt.
        $this->actingAs($a)->get(route('sales.index', ['tab' => 'transactions']))->assertOk()->assertSee($saleA->sale_number)->assertDontSee($saleB->sale_number);
        $this->actingAs($a)->get(route('sales.show', $saleB))->assertForbidden();
        $this->actingAs($a)->get(route('sales.receipt', $saleB))->assertForbidden();

        // Manager of another branch sees nothing of this branch.
        $manager = $this->addMember($company, SystemRole::BranchManager, [$this->createBranch($company)]);
        $this->actingAs($manager)->get(route('sales.index'))->assertOk()->assertDontSee($saleA->sale_number);
        $this->actingAs($manager)->get(route('sales.show', $saleA))->assertForbidden();

        // Another company: 404 (tenant scope).
        app(TenantContext::class)->clear();
        $other = $this->createCompany();
        $stranger = $this->createOwner($other);
        $this->actingAs($stranger)->get(route('sales.show', $saleA))->assertNotFound();
        $this->actingAs($stranger)->get(route('sales.receipt', $saleA))->assertNotFound();
        $this->actingAs($stranger)->get(route('sales.index'))->assertOk()->assertDontSee($saleA->sale_number);
    }

    public function test_reports_exclude_change_and_net_returns(): void
    {
        ['company' => $company, 'owner' => $owner, 'saleA' => $sale] = $this->soldBy();
        $this->actingAsTenant($owner, $company);

        Livewire::test(SaleShow::class, ['sale' => $sale])
            ->call('startReturn')
            ->set('returnItems.'.$sale->items()->sole()->id.'.quantity', '1')
            ->set('reason', 'Wrong item')
            ->call('processReturn')->assertHasNoErrors();
        $this->assertSame('2500.00', SaleReturn::forCompany($company)->sole()->refund_total);

        $page = $this->actingAs($owner)->get(route('sales.reports'))->assertOk();
        $page->assertSee('10,000.00');   // 2 sales x 5,000 (not 20,000 tendered)
        $page->assertSee('7,500.00');    // net sales after the 2,500 refund
        $page->assertSee('3,000.00');    // COGS 4 sold - 1 returned at 1,000

        $this->actingAs($owner)->get(route('sales.show', $sale))->assertOk()->assertSee('RET-000001');
    }

    public function test_customers_are_company_scoped(): void
    {
        ['company' => $company, 'owner' => $owner] = $this->soldBy();
        $this->actingAsTenant($owner, $company);
        app(CustomerService::class)->save($company, null, ['name' => 'Mama Neema', 'phone' => '0712 345 678'], $owner);

        // Same phone in another format is a duplicate within the company.
        Livewire::test(CustomerIndex::class)->call('create')->set('form.name', 'Dup')->set('form.phone', '+255712345678')->call('save')->assertHasErrors('form.phone');

        app(TenantContext::class)->clear();
        $other = $this->createCompany();
        $stranger = $this->createOwner($other);
        $this->actingAs($stranger)->get(route('customers.index'))->assertOk()->assertDontSee('Mama Neema');
        $this->actingAsTenant($stranger, $other);
        $this->assertNotNull(app(CustomerService::class)->save($other, null, ['name' => 'Other', 'phone' => '0712345678'], $stranger), 'Same phone allowed in another company.');
        $this->assertSame(1, Customer::forCompany($company)->count());
    }

    public function test_pages_render_for_owner_and_in_kiswahili(): void
    {
        ['owner' => $owner, 'saleA' => $sale] = $this->soldBy();

        foreach ([route('sales.index'), route('sales.show', $sale), route('sales.reports'), route('customers.index'), route('settings.payment-methods'), route('pos'), route('company.pos-devices.index'), route('inventory.locations'), route('company.staff.index')] as $url) {
            $this->actingAs($owner)->get($url)->assertOk();
        }

        $owner->forceFill(['locale' => 'sw'])->save();
        $this->actingAs($owner->fresh())->get(route('sales.index'))->assertSee('Mauzo');
        $this->actingAs($owner->fresh())->get(route('sales.receipt', $sale))->assertSee('Asante');
    }

    public function test_cashier_can_be_added_and_logs_in_to_pos(): void
    {
        ['company' => $company, 'owner' => $owner] = $this->soldBy();
        $this->actingAsTenant($owner, $company);

        Livewire::test(AddStaffMember::class)
            ->set('name', 'Asha Cashier')->set('email', 'asha.cashier@example.test')
            ->set('password', 'Secret123pass')->set('password_confirmation', 'Secret123pass')
            ->set('role', 'cashier')
            ->call('save')->assertHasNoErrors();

        app(TenantContext::class)->clear();
        $this->app['auth']->forgetGuards();
        $this->post('/login', ['email' => 'asha.cashier@example.test', 'password' => 'Secret123pass'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertRedirect(route('pos'));
    }
}
