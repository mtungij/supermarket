<?php

namespace Tests\Feature\Inventory;

use App\Exceptions\BusinessRuleException;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\Unit;
use App\Services\Catalogue\BrandService;
use App\Services\Catalogue\CategoryService;
use App\Services\Catalogue\CompanyDefaultsProvisioner;
use App\Services\Catalogue\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesInventory;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class CatalogueServicesTest extends TestCase
{
    use CreatesInventory, CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    public function test_new_company_gets_default_categories_units_and_locations_idempotently(): void
    {
        $company = $this->createCompany();
        $other = $this->createCompany();
        $before = Category::forCompany($other)->count();

        $this->assertSame(15, Category::forCompany($company)->count());
        $this->assertTrue(Category::forCompany($company)->where('name', 'Fruits & Vegetables')->exists());
        $this->assertSame(12, Unit::forCompany($company)->count());
        $this->assertSame(4, StockLocation::forCompany($company)->count()); // + Quarantine

        $again = app(CompanyDefaultsProvisioner::class)->provision($company);

        $this->assertSame(['units' => 0, 'categories' => 0, 'locations' => 0, 'payment_methods' => 0], $again);
        $this->assertSame(15, Category::forCompany($company)->count());
        $this->assertSame($before, Category::forCompany($other)->count(), 'Other companies are untouched.');
    }

    public function test_registration_provisions_defaults(): void
    {
        $this->post('/register', [
            'company_name' => 'Duka Bora', 'company_email' => 'info@dukabora.test', 'company_phone' => '+255 712 000 111',
            'business_address' => 'Arusha', 'branch_name' => 'Main', 'owner_name' => 'Neema',
            'owner_email' => 'neema@dukabora.test', 'password' => 'Secret123pass', 'password_confirmation' => 'Secret123pass',
        ])->assertRedirect('/dashboard');

        $company = Company::query()->where('email', 'info@dukabora.test')->firstOrFail();
        $this->assertSame(15, Category::forCompany($company)->count());
        $this->assertSame(4, StockLocation::forCompany($company)->count()); // + Quarantine
    }

    public function test_category_crud_rules(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $service = app(CategoryService::class);

        $drinks = $service->create($company, 'Soft Drinks', 'Sodas', $this->defaultCategory($company)->id);
        $this->assertSame($this->defaultCategory($company)->id, $drinks->parent_id);

        // Normalised uniqueness.
        try {
            $service->create($company, '  soft   DRINKS ');
            $this->fail('Duplicate category accepted.');
        } catch (BusinessRuleException) {
        }

        // Same name allowed in another company.
        $this->assertNotNull($service->create($this->createCompany(), 'Soft Drinks'));

        // Cycles rejected.
        $child = $service->create($company, 'Energy Drinks', null, $drinks->id);
        try {
            $service->update($drinks, 'Soft Drinks', null, $child->id);
            $this->fail('Cycle accepted.');
        } catch (BusinessRuleException) {
        }

        // Cannot delete when referenced by products; can deactivate.
        $this->makeProduct($company, $owner, ['category_id' => $child->id]);
        $this->expectException(BusinessRuleException::class);
        try {
            $service->delete($child);
        } finally {
            $service->setActive($child, false);
            $this->assertFalse($child->fresh()->is_active);
        }
    }

    public function test_unused_category_can_be_deleted(): void
    {
        $company = $this->createCompany();
        $category = app(CategoryService::class)->create($company, 'Temporary');

        app(CategoryService::class)->delete($category);

        $this->assertNull(Category::forCompany($company)->find($category->id));
    }

    public function test_brand_names_are_unique_per_company_with_normalisation(): void
    {
        $company = $this->createCompany();
        $service = app(BrandService::class);

        $service->save($company, null, 'Azam', null);
        $this->assertNotNull($service->save($this->createCompany(), null, 'AZAM', null));

        $this->expectException(BusinessRuleException::class);
        $service->save($company, null, '  azam ', null);
    }

    public function test_product_creation_rules(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);

        $product = $this->makeProduct($company, $owner, ['name' => 'Azam Juice 500ml', 'sku' => 'aj-500', 'barcode' => '4006381333931']);

        $this->assertSame('AJ-500', $product->sku);
        $this->assertSame('1500.00', $product->selling_price);
        $this->assertSame('1000.0000', $product->purchase_cost);
        $this->assertTrue($this->baseUnitOf($product)->is_base);
        $this->assertSame('1.000000', $this->baseUnitOf($product)->factor);
        $this->assertSame('4006381333931', $product->barcodes()->first()->barcode);

        // Duplicate SKU rejected within the company, allowed elsewhere.
        $other = $this->createCompany();
        $this->assertNotNull($this->makeProduct($other, $this->createOwner($other), ['sku' => 'AJ-500']));
        $this->expectException(BusinessRuleException::class);
        $this->makeProduct($company, $owner, ['sku' => 'AJ-500']);
    }

    public function test_product_type_must_match_base_unit_dimension(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);

        $rice = $this->makeProduct($company, $owner, ['product_type' => 'weighed', 'base_unit_id' => $this->unit($company, 'kg')->id]);
        $this->assertSame('weighed', $rice->product_type->value);

        $this->expectException(BusinessRuleException::class);
        $this->makeProduct($company, $owner, ['product_type' => 'weighed', 'base_unit_id' => $this->unit($company, 'pc')->id]);
    }

    public function test_foreign_category_or_unit_is_rejected(): void
    {
        $company = $this->createCompany();
        $other = $this->createCompany();
        $owner = $this->createOwner($company);

        $this->expectException(BusinessRuleException::class);
        $this->makeProduct($company, $owner, ['category_id' => $this->defaultCategory($other)->id]);
    }

    public function test_base_unit_cannot_change_after_transactions(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $product = $this->makeProduct($company, $owner);
        $this->approvedOrder($company, $this->headOffice($company), $this->makeSupplier($company), [[$product, $this->baseUnitOf($product), '5', '1000']], $owner);

        $this->expectException(BusinessRuleException::class);
        app(ProductService::class)->update($product, [
            'name' => $product->name, 'category_id' => $product->category_id, 'base_unit_id' => $this->unit($company, 'btl')->id,
            'product_type' => 'standard', 'tax_class' => 'standard',
        ], $owner);
    }

    public function test_products_are_company_scoped_and_not_duplicated_per_branch(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->createBranch($company);
        $this->makeProduct($company, $owner);

        $this->assertSame(1, Product::forCompany($company)->count());
        $this->assertSame(0, Brand::forCompany($company)->count());
    }
}
