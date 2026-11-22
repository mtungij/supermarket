<?php

namespace Tests\Feature\Inventory;

use App\Exceptions\BusinessRuleException;
use App\Livewire\Catalogue\ProductForm;
use App\Models\Company;
use App\Models\Product;
use App\Services\Catalogue\ProductImageService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\CreatesInventory;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class ProductImageTest extends TestCase
{
    use CreatesInventory, CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    private function form(Company $company)
    {
        return Livewire::test(ProductForm::class)
            ->set('name', 'Cooking Oil 1L')
            ->set('category', $this->defaultCategory($company)->uuid);
    }

    public function test_valid_image_is_reencoded_and_stored_under_a_tenant_path(): void
    {
        Storage::fake('local');
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);

        $this->form($company)->set('image', UploadedFile::fake()->image('oil.png', 2400, 1600))->call('save')->assertHasNoErrors();

        $product = Product::forCompany($company)->sole();
        $this->assertMatchesRegularExpression('#^companies/'.$company->uuid.'/products/'.$product->uuid.'-[a-z0-9]{12}\.webp$#', $product->image_path);
        Storage::disk('local')->assertExists($product->image_path);

        [$w, $h] = getimagesizefromstring(Storage::disk('local')->get($product->image_path));
        $this->assertSame(1200, $w, 'Resized to the configured maximum.');
        $this->assertSame(800, $h);

        $this->actingAs($owner)->get(route('catalogue.products.image', $product))
            ->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_non_images_and_disguised_files_are_rejected(): void
    {
        Storage::fake('local');
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);

        $cases = [
            UploadedFile::fake()->createWithContent('shell.php', '<?php echo "x";'),
            UploadedFile::fake()->createWithContent('shell.png', '<?php system($_GET["c"]); ?>'),
            UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        ];

        foreach ($cases as $file) {
            $this->form($company)->set('image', $file)->call('save')->assertHasErrors('image');
        }

        $this->assertSame(0, Product::forCompany($company)->count());
        $this->assertSame([], Storage::disk('local')->allFiles('companies'));

        // Size limit enforced by the service too (defence in depth).
        $product = $this->makeProduct($company, $owner);
        $this->expectException(BusinessRuleException::class);
        app(ProductImageService::class)->store($product, UploadedFile::fake()->image('huge.jpg')->size(5000));
    }

    public function test_images_are_private_and_tenant_scoped(): void
    {
        Storage::fake('local');
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $this->form($company)->set('image', UploadedFile::fake()->image('oil.jpg'))->call('save');
        $product = Product::forCompany($company)->sole();
        app(TenantContext::class)->clear();

        $other = $this->createCompany();
        $outsider = $this->createOwner($other);

        $this->actingAs($outsider)->get(route('catalogue.products.image', $product))->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->get(route('catalogue.products.image', $product))->assertRedirect(route('login'));
        $this->assertStringNotContainsString('public', config('filesystems.disks.local.root'));
    }
}
