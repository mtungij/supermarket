<?php

namespace Tests\Feature\Inventory;

use App\Enums\SystemRole;
use App\Models\ProductPrice;
use App\Services\Catalogue\PriceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesInventory;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class PricingTest extends TestCase
{
    use CreatesInventory, CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    public function test_unit_specific_and_branch_specific_prices(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $hq = $this->headOffice($company);
        $mbezi = $this->createBranch($company);
        $prices = app(PriceService::class);

        $juice = $this->makeProduct($company, $owner, ['selling_price' => '1500']);
        $piece = $this->baseUnitOf($juice);
        $pack = $this->packaging($juice, 'pack', '6');
        $carton = $this->packaging($juice, 'ctn', '24');

        $prices->setPrice($juice, $pack, '8000', null, $owner);
        $prices->setPrice($juice, $carton, '30000', null, $owner);
        $prices->setPrice($juice, $piece, '1600', $mbezi, $owner);

        $this->assertSame('1500.00', $prices->resolve($juice, $piece, $hq)->price);
        $this->assertSame('1600.00', $prices->resolve($juice, $piece, $mbezi)->price);
        $this->assertSame('branch_unit', $prices->resolve($juice, $piece, $mbezi)->source);
        $this->assertSame('8000.00', $prices->resolve($juice, $pack, $hq)->price);
        $this->assertSame('30000.00', $prices->resolve($juice, $carton, $mbezi)->price);
    }

    public function test_unit_without_price_is_derived_from_base_price(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $juice = $this->makeProduct($company, $owner, ['selling_price' => '1500']);
        $pack = $this->packaging($juice, 'pack', '6');

        $quote = app(PriceService::class)->resolve($juice, $pack);

        $this->assertSame('9000.00', $quote->price);
        $this->assertSame('derived_from_base', $quote->source);
    }

    public function test_effective_dated_prices_and_append_only_history(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $juice = $this->makeProduct($company, $owner, ['selling_price' => '1500']);
        $piece = $this->baseUnitOf($juice);
        $prices = app(PriceService::class);

        $prices->setPrice($juice, $piece, '1700', null, $owner, now()->addDays(7));

        $this->assertSame('1500.00', $prices->resolve($juice, $piece)->price, 'Future price not yet in effect.');
        $this->assertSame('1700.00', $prices->resolve($juice, $piece, null, now()->addDays(8))->price);

        $this->travel(8)->days();
        $this->assertSame('1700.00', $prices->resolve($juice, $piece)->price);
        $this->travelBack();

        $prices->setPrice($juice, $piece, '1550', null, $owner);
        $this->assertSame('1550.00', $juice->fresh()->selling_price, 'Default price follows the current company-wide base price.');
        $this->assertSame(2, ProductPrice::forCompany($company)->where('product_id', $juice->id)->count());

        $this->expectException(\LogicException::class);
        ProductPrice::forCompany($company)->first()->forceFill(['price' => '1'])->save();
    }

    public function test_back_dated_and_invalid_prices_are_rejected(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $juice = $this->makeProduct($company, $owner);
        $prices = app(PriceService::class);

        foreach ([['-5', null], ['10.555', null], ['abc', null], ['100', now()->subDay()]] as [$price, $from]) {
            try {
                $prices->setPrice($juice, $this->baseUnitOf($juice), $price, null, $owner, $from);
                $this->fail("Price [{$price}] accepted.");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_price_management_requires_permission(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $juice = $this->makeProduct($company, $owner);
        $cashier = $this->addMember($company, SystemRole::Cashier, [$this->headOffice($company)]);
        $this->actingAsTenant($cashier, $company);

        $this->expectException(AuthorizationException::class);
        app(PriceService::class)->setPrice($juice, $this->baseUnitOf($juice), '1', null, $cashier);
    }

    public function test_branch_price_requires_branch_access(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $juice = $this->makeProduct($company, $owner);
        $mine = $this->createBranch($company);
        $notMine = $this->createBranch($company);
        $manager = $this->addMember($company, SystemRole::BranchManager, [$mine]);
        $this->actingAsTenant($manager, $company);

        app(PriceService::class)->setPrice($juice, $this->baseUnitOf($juice), '1800', $mine, $manager);

        $this->expectException(AuthorizationException::class);
        app(PriceService::class)->setPrice($juice, $this->baseUnitOf($juice), '1800', $notMine, $manager);
    }
}
