<?php

namespace Tests\Feature\Inventory;

use App\Exceptions\InvalidUnitConversionException;
use App\Models\PurchaseOrderLine;
use App\Services\Catalogue\UnitConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesInventory;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class UnitConversionTest extends TestCase
{
    use CreatesInventory, CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    public function test_packaging_factors_are_product_specific(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $a = $this->makeProduct($company, $owner, ['name' => 'Product A']);
        $b = $this->makeProduct($company, $owner, ['name' => 'Product B']);

        $cartonA = $this->packaging($a, 'ctn', '24');
        $cartonB = $this->packaging($b, 'ctn', '12');
        $service = app(UnitConversionService::class);

        $this->assertSame('48.0000', $service->toBase($a, $cartonA, '2'));
        $this->assertSame('24.0000', $service->toBase($b, $cartonB, '2'));
        $this->assertSame('12.0000', $service->toBase($a, $cartonA, '0.5'), 'Half a carton of 24 is 12 pieces.');
    }

    public function test_measurement_units_use_physical_ratios(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $sugar = $this->makeProduct($company, $owner, ['product_type' => 'weighed', 'base_unit_id' => $this->unit($company, 'kg')->id]);
        $oil = $this->makeProduct($company, $owner, ['product_type' => 'variable_measurement', 'base_unit_id' => $this->unit($company, 'l')->id]);
        $service = app(UnitConversionService::class);

        $gram = $service->setUnit($sugar, $this->unit($company, 'g'), null);
        $this->assertSame('0.001000', $gram->factor);
        $this->assertSame('0.2500', $service->toBase($sugar, $gram, '250'));

        $ml = $service->setUnit($oil, $this->unit($company, 'ml'), '0.001');
        $this->assertSame('0.001000', $ml->factor);

        // Packaging of a weighed product: 1 bag = 25 kg (product-specific).
        $bag = $service->setUnit($sugar, $this->unit($company, 'bag'), '25');
        $this->assertSame('50.0000', $service->toBase($sugar, $bag, '2'));
    }

    public function test_wrong_physical_factor_is_rejected(): void
    {
        $company = $this->createCompany();
        $sugar = $this->makeProduct($company, $this->createOwner($company), ['product_type' => 'weighed', 'base_unit_id' => $this->unit($company, 'kg')->id]);

        $this->expectException(InvalidUnitConversionException::class);
        app(UnitConversionService::class)->setUnit($sugar, $this->unit($company, 'g'), '0.01');
    }

    public function test_incompatible_dimensions_are_rejected(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $sugar = $this->makeProduct($company, $owner, ['product_type' => 'weighed', 'base_unit_id' => $this->unit($company, 'kg')->id]);
        $soap = $this->makeProduct($company, $owner);
        $service = app(UnitConversionService::class);

        foreach ([[$sugar, 'l', null], [$soap, 'kg', '1']] as [$product, $code, $factor]) {
            try {
                $service->setUnit($product, $this->unit($company, $code), $factor);
                $this->fail("{$code} accepted for {$product->name}");
            } catch (InvalidUnitConversionException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_invalid_factors_are_rejected(): void
    {
        $company = $this->createCompany();
        $soap = $this->makeProduct($company, $this->createOwner($company));
        $service = app(UnitConversionService::class);

        foreach (['0', '-6', 'abc', '', '6.5', '1', '1.1234567'] as $factor) {
            try {
                $service->setUnit($soap, $this->unit($company, 'pack'), $factor);
                $this->fail("Factor [{$factor}] accepted.");
            } catch (InvalidUnitConversionException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_quantities_are_validated(): void
    {
        $company = $this->createCompany();
        $soap = $this->makeProduct($company, $this->createOwner($company));
        $base = $this->baseUnitOf($soap);
        $service = app(UnitConversionService::class);

        foreach (['0', '-1', '1.5', 'x', '1.00001'] as $qty) {
            try {
                $service->toBase($soap, $base, $qty);
                $this->fail("Quantity [{$qty}] accepted for a standard product.");
            } catch (InvalidUnitConversionException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_conversion_snapshots_survive_factor_changes(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $juice = $this->makeProduct($company, $owner);
        $carton = $this->packaging($juice, 'ctn', '24');

        $order = $this->approvedOrder($company, $this->headOffice($company), $this->makeSupplier($company), [[$juice, $carton, '2', '30000']], $owner);

        // Supplier changes packaging: from now on a carton holds 20.
        $this->packaging($juice, 'ctn', '20');

        $line = PurchaseOrderLine::forCompany($company)->where('purchase_order_id', $order->id)->sole();
        $this->assertSame('24.000000', $line->conversion_factor);
        $this->assertSame('48.0000', $line->quantity_base);
    }
}
