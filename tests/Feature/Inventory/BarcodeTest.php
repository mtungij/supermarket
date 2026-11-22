<?php

namespace Tests\Feature\Inventory;

use App\Enums\BarcodeType;
use App\Exceptions\BusinessRuleException;
use App\Services\Catalogue\BarcodeService;
use App\Support\Barcode\Gtin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesInventory;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class BarcodeTest extends TestCase
{
    use CreatesInventory, CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    public function test_barcodes_are_unique_per_company_only(): void
    {
        $a = $this->createCompany();
        $b = $this->createCompany();
        $service = app(BarcodeService::class);

        $p1 = $this->makeProduct($a, $this->createOwner($a));
        $p2 = $this->makeProduct($a, $this->createOwner($b) /* actor unused for create */);
        $p3 = $this->makeProduct($b, $this->createOwner($b));

        $service->add($p1, '4006381333931', BarcodeType::Manufacturer);
        $service->add($p3, '4006381333931', BarcodeType::Manufacturer); // same GTIN, other company: OK

        $this->expectException(BusinessRuleException::class);
        $service->add($p2, '4006381333931', BarcodeType::Manufacturer);
    }

    public function test_manufacturer_gtin_check_digit_is_validated(): void
    {
        $company = $this->createCompany();
        $product = $this->makeProduct($company, $this->createOwner($company));

        $this->expectException(BusinessRuleException::class);
        app(BarcodeService::class)->add($product, '4006381333932', BarcodeType::Manufacturer);
    }

    public function test_internal_barcodes_are_valid_sequential_ean13(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $service = app(BarcodeService::class);

        $first = $service->generateInternal($this->makeProduct($company, $owner));
        $second = $service->generateInternal($this->makeProduct($company, $owner));

        $this->assertMatchesRegularExpression('/^20\d{11}$/', $first->barcode);
        $this->assertTrue(Gtin::isValid($first->barcode));
        $this->assertTrue(Gtin::isValid($second->barcode));
        $this->assertNotSame($first->barcode, $second->barcode);
        $this->assertSame(BarcodeType::Internal, $first->type);
        $this->assertTrue($first->is_primary);
    }

    public function test_packaging_barcode_maps_to_its_unit_and_lookup_works(): void
    {
        $company = $this->createCompany();
        $juice = $this->makeProduct($company, $this->createOwner($company), ['sku' => 'JUICE-1', 'barcode' => '6001234567899']);
        $carton = $this->packaging($juice, 'ctn', '24');
        $service = app(BarcodeService::class);

        $service->add($juice, '16001234567896', BarcodeType::Packaging, $carton);

        $piece = $service->lookup($company, ' 6001234567899 ');
        $this->assertSame($juice->id, $piece['product']->id);
        $this->assertTrue($piece['product_unit']->is_base);

        $box = $service->lookup($company, '16001234567896');
        $this->assertSame($carton->id, $box['product_unit']->id);
        $this->assertSame('24.000000', $box['product_unit']->factor);

        $bySku = $service->lookup($company, 'juice-1');
        $this->assertSame($juice->id, $bySku['product']->id);

        $this->assertNull($service->lookup($this->createCompany(), '6001234567899'), 'Other companies cannot look it up.');
        $this->assertNull($service->lookup($company, 'NOPE'));
    }

    public function test_packaging_barcode_requires_packaging_unit(): void
    {
        $company = $this->createCompany();
        $juice = $this->makeProduct($company, $this->createOwner($company));

        $this->expectException(BusinessRuleException::class);
        app(BarcodeService::class)->add($juice, '16001234567896', BarcodeType::Packaging);
    }

    public function test_only_one_primary_barcode(): void
    {
        $company = $this->createCompany();
        $juice = $this->makeProduct($company, $this->createOwner($company), ['barcode' => '6001234567899']);
        $service = app(BarcodeService::class);

        $second = $service->add($juice, 'INT-001', BarcodeType::Internal, primary: true);

        $this->assertSame(1, $juice->barcodes()->where('is_primary', true)->count());
        $this->assertTrue($second->fresh()->is_primary);
    }
}
