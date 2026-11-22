<?php

namespace App\Livewire\Catalogue;

use App\Enums\BarcodeType;
use App\Enums\ProductType;
use App\Enums\TaxClass;
use App\Livewire\Concerns\InteractsWithTenant;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Services\Catalogue\ProductImageService;
use App\Services\Catalogue\ProductService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Create / edit a product. Related records are selected by UUID and
 * resolved through tenant-scoped queries; the service re-validates them.
 */
#[Layout('components.layouts.tenant')]
class ProductForm extends Component
{
    use InteractsWithTenant, WithFileUploads;

    #[Locked]
    public ?string $productUuid = null;

    public string $name = '';

    public string $sku = '';

    public string $description = '';

    public string $category = '';

    public string $brand = '';

    public string $baseUnit = '';

    public string $productType = 'standard';

    public string $taxClass = 'standard';

    public string $purchaseCost = '0';

    public string $sellingPrice = '0';

    public string $minStockLevel = '0';

    public string $reorderLevel = '0';

    public bool $trackExpiry = false;

    public bool $trackBatches = false;

    public string $barcode = '';

    public string $barcodeType = 'manufacturer';

    public $image = null;

    public function mount(?Product $product = null): void
    {
        if ($product?->exists) {
            $this->authorize('products.update');
            $this->productUuid = $product->uuid;
            $this->fill([
                'name' => $product->name, 'sku' => $product->sku, 'description' => (string) $product->description,
                'category' => $product->category->uuid, 'brand' => (string) $product->brand?->uuid,
                'baseUnit' => $product->baseUnit->uuid, 'productType' => $product->product_type->value,
                'taxClass' => $product->tax_class->value, 'purchaseCost' => $product->purchase_cost,
                'sellingPrice' => $product->selling_price, 'minStockLevel' => $product->min_stock_level,
                'reorderLevel' => $product->reorder_level, 'trackExpiry' => $product->track_expiry,
                'trackBatches' => $product->track_batches,
            ]);
        } else {
            $this->authorize('products.create');
            $this->baseUnit = (string) Unit::query()->where('code', 'pc')->value('uuid');
        }
    }

    public function save(ProductService $products, ProductImageService $images): mixed
    {
        $this->authorize($this->productUuid ? 'products.update' : 'products.create');

        $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'sku' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['required', 'uuid'],
            'brand' => ['nullable', 'uuid'],
            'baseUnit' => ['required', 'uuid'],
            'productType' => ['required', Rule::enum(ProductType::class)],
            'taxClass' => ['required', Rule::enum(TaxClass::class)],
            'purchaseCost' => ['required', 'regex:/^\d{1,14}(\.\d{1,4})?$/'],
            'sellingPrice' => ['required', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'minStockLevel' => ['required', 'regex:/^\d{1,14}(\.\d{1,4})?$/'],
            'reorderLevel' => ['required', 'regex:/^\d{1,14}(\.\d{1,4})?$/'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'barcodeType' => ['required', Rule::enum(BarcodeType::class)],
            // Image: extension + MIME sniffing + size here; decoded and re-encoded by ProductImageService.
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('inventory.product_images.max_kilobytes')],
        ]);

        $data = [
            'name' => $this->name,
            'sku' => $this->sku,
            'description' => $this->description,
            'category_id' => Category::query()->where('uuid', $this->category)->value('id'),
            'brand_id' => $this->brand ? Brand::query()->where('uuid', $this->brand)->value('id') : null,
            'base_unit_id' => Unit::query()->where('uuid', $this->baseUnit)->value('id'),
            'product_type' => $this->productType,
            'tax_class' => $this->taxClass,
            'purchase_cost' => $this->purchaseCost,
            'selling_price' => $this->sellingPrice,
            'min_stock_level' => $this->minStockLevel,
            'reorder_level' => $this->reorderLevel,
            'track_expiry' => $this->trackExpiry,
            'track_batches' => $this->trackBatches,
            'barcode' => $this->barcode,
            'barcode_type' => $this->barcodeType,
        ];

        // One transaction: a rejected image (decoded/re-encoded by the image
        // service) rolls back the product save as well.
        $product = DB::transaction(function () use ($products, $images, $data) {
            $product = $this->productUuid
                ? $products->update(Product::query()->where('uuid', $this->productUuid)->firstOrFail(), $data, $this->actor())
                : $products->create($this->company(), $data, $this->actor());

            if ($this->image !== null) {
                $images->store($product, $this->image);
            }

            return $product;
        });

        session()->flash('status', __('Product saved.'));

        return $this->redirectRoute('catalogue.products.show', $product, navigate: false);
    }

    public function render(): View
    {
        return view('livewire.catalogue.product-form', [
            'categories' => Category::query()->where('is_active', true)->orderBy('name')->get(['uuid', 'name']),
            'brands' => Brand::query()->where('is_active', true)->orderBy('name')->get(['uuid', 'name']),
            'units' => Unit::query()->where('is_active', true)->orderBy('dimension')->orderBy('name')->get(['uuid', 'name', 'dimension']),
            'types' => ProductType::cases(),
            'taxClasses' => TaxClass::cases(),
            'barcodeTypes' => [BarcodeType::Manufacturer, BarcodeType::Internal],
            'editing' => $this->productUuid !== null,
        ])->title($this->productUuid ? __('Edit product') : __('New product'));
    }
}
