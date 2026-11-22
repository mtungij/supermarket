<?php

namespace App\Livewire\Catalogue;

use App\Enums\BarcodeType;
use App\Enums\ProductType;
use App\Enums\TaxClass;
use App\Livewire\Concerns\InteractsWithTenant;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Services\Catalogue\BarcodeService;
use App\Services\Catalogue\BrandService;
use App\Services\Catalogue\CategoryService;
use App\Services\Catalogue\PriceService;
use App\Services\Catalogue\ProductImageService;
use App\Services\Catalogue\ProductService;
use App\Support\Decimal;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * One-step product registration (and the matching edit form).
 *
 * Essentials (name, barcode, SKU, category, brand, type, base unit,
 * selling price, reference buying price, tax) are on one screen; batch /
 * expiry tracking, stock levels, extra barcodes, packaging units,
 * description and image sit in a collapsed "Advanced settings" section.
 *
 * All business rules stay in the services: ProductService creates the
 * product + base unit + barcodes + packaging in ONE transaction, and the
 * selling price is written through PriceService (price history,
 * products.prices.manage). Nothing here creates stock or costs.
 * Related records are chosen by UUID and resolved through tenant scopes.
 */
#[Layout('components.layouts.tenant')]
class ProductForm extends Component
{
    use InteractsWithTenant, WithFileUploads;

    #[Locked]
    public ?string $productUuid = null;

    /** Idempotency key: a double-clicked Save never creates two products. */
    #[Locked]
    public string $submissionKey = '';

    public string $name = '';

    public string $sku = '';

    public string $description = '';

    public string $category = '';

    public string $brand = '';

    public string $baseUnit = '';

    public string $productType = 'standard';

    public string $taxClass = 'standard';

    public string $sellingPrice = '';

    public string $buyingPrice = '';

    public string $minStockLevel = '0';

    public string $reorderLevel = '0';

    public bool $trackExpiry = false;

    public bool $trackBatches = false;

    public string $barcode = '';

    public string $barcodeType = 'manufacturer';

    public bool $generateBarcode = false;

    /** @var list<string> */
    public array $extraBarcodes = [];

    /** @var list<array{unit: string, factor: string, barcode: string, price: string, purchase: bool, sell: bool}> */
    public array $packaging = [];

    public $image = null;

    public bool $showAdvanced = false;

    public string $newCategoryName = '';

    public string $newBrandName = '';

    public string $savedMessage = '';

    public ?string $savedUuid = null;

    public function mount(?Product $product = null): void
    {
        $this->submissionKey = (string) Str::uuid();

        if ($product?->exists) {
            $this->authorize('products.update');
            $this->productUuid = $product->uuid;
            $base = ProductUnit::query()->where('product_id', $product->id)->where('is_base', true)->firstOrFail();
            $this->fill([
                'name' => $product->name, 'sku' => $product->sku, 'description' => (string) $product->description,
                'category' => $product->category->uuid, 'brand' => (string) $product->brand?->uuid,
                'baseUnit' => $product->baseUnit->uuid, 'productType' => $product->product_type->value,
                'taxClass' => $product->tax_class->value,
                'sellingPrice' => Decimal::trim(app(PriceService::class)->resolve($product, $base)->price),
                'buyingPrice' => $product->reference_buying_price !== null ? Decimal::trim($product->reference_buying_price) : '',
                'minStockLevel' => Decimal::trim($product->min_stock_level), 'reorderLevel' => Decimal::trim($product->reorder_level),
                'trackExpiry' => $product->track_expiry, 'trackBatches' => $product->track_batches,
            ]);
        } else {
            $this->authorize('products.create');
            $this->resetForNew();
        }
    }

    private function resetForNew(bool $keepGrouping = false): void
    {
        $keep = $keepGrouping ? ['category' => $this->category, 'brand' => $this->brand, 'baseUnit' => $this->baseUnit, 'productType' => $this->productType, 'taxClass' => $this->taxClass] : [];

        $this->reset(['name', 'sku', 'description', 'category', 'brand', 'sellingPrice', 'buyingPrice', 'minStockLevel', 'reorderLevel',
            'trackExpiry', 'trackBatches', 'barcode', 'barcodeType', 'generateBarcode', 'extraBarcodes', 'packaging', 'image', 'productType']);
        $this->resetValidation();
        $this->baseUnit = (string) Unit::query()->where('code', 'pc')->where('is_active', true)->value('uuid');
        $this->taxClass = TaxClass::tryFrom((string) data_get($this->company()->settings, 'default_tax_class'))?->value ?? TaxClass::Standard->value;
        $this->fill($keep);
        $this->submissionKey = (string) Str::uuid();
    }

    /** Keep the base unit valid for the chosen type (e.g. Weighed -> kg). */
    public function updatedProductType(string $value): void
    {
        $type = ProductType::tryFrom($value) ?? ProductType::Standard;
        $current = Unit::query()->where('uuid', $this->baseUnit)->first();

        if ($current === null || ! in_array($current->dimension, $type->allowedBaseDimensions(), true)) {
            $preferred = match ($type) {
                ProductType::Standard => 'pc',
                ProductType::Weighed, ProductType::VariableMeasurement => 'kg',
            };
            $this->baseUnit = (string) (Unit::query()->where('is_active', true)->where('code', $preferred)->value('uuid')
                ?? Unit::query()->where('is_active', true)->whereIn('dimension', $type->allowedBaseDimensions())->orderBy('name')->value('uuid'));
        }
    }

    // ---- Advanced rows -------------------------------------------------------

    public function addBarcodeRow(): void
    {
        $this->extraBarcodes[] = '';
    }

    public function removeBarcodeRow(int $i): void
    {
        unset($this->extraBarcodes[$i]);
        $this->extraBarcodes = array_values($this->extraBarcodes);
    }

    public function addPackagingRow(): void
    {
        $this->packaging[] = ['unit' => '', 'factor' => '', 'barcode' => '', 'price' => '', 'purchase' => true, 'sell' => true];
    }

    public function removePackagingRow(int $i): void
    {
        unset($this->packaging[$i]);
        $this->packaging = array_values($this->packaging);
    }

    // ---- Quick category / brand (same permissions as the catalogue pages) --

    public function createCategory(CategoryService $categories): void
    {
        $this->authorize('categories.manage');
        $this->validate(['newCategoryName' => ['required', 'string', 'max:100']], [], ['newCategoryName' => __('Category name')]);

        try {
            $category = $categories->create($this->company(), $this->newCategoryName);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['newCategoryName' => collect($e->errors())->flatten()->all()]);
        }

        $this->category = $category->uuid;
        $this->newCategoryName = '';
        $this->resetValidation(['category', 'category_id']);
        $this->dispatch('quick-created', field: 'category', message: __('Category ":name" added.', ['name' => $category->name]));
    }

    public function createBrand(BrandService $brands): void
    {
        $this->authorize('brands.manage');
        $this->validate(['newBrandName' => ['required', 'string', 'max:100']], [], ['newBrandName' => __('Brand name')]);

        try {
            $brand = $brands->save($this->company(), null, $this->newBrandName, null);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['newBrandName' => collect($e->errors())->flatten()->all()]);
        }

        $this->brand = $brand->uuid;
        $this->newBrandName = '';
        $this->dispatch('quick-created', field: 'brand', message: __('Brand ":name" added.', ['name' => $brand->name]));
    }

    // ---- Save ---------------------------------------------------------------

    private function canSetPrices(): bool
    {
        return auth()->user()->can('products.prices.manage');
    }

    /** $then: "show" (open product details) or "another" (Save & add another). */
    public function save(ProductService $products, ProductImageService $images, BarcodeService $barcodes, string $then = 'show'): mixed
    {
        $editing = $this->productUuid !== null;
        $this->authorize($editing ? 'products.update' : 'products.create');

        $money = 'regex:/^\d{1,13}(\.\d{1,2})?$/';
        $qty = 'regex:/^\d{1,14}(\.\d{1,4})?$/';
        $this->sellingPrice = str_replace([',', ' '], '', trim($this->sellingPrice));
        $this->buyingPrice = str_replace([',', ' '], '', trim($this->buyingPrice));
        $this->barcode = trim($this->barcode);

        $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'sku' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['required', 'uuid'],
            'brand' => ['nullable', 'uuid'],
            'baseUnit' => ['required', 'uuid'],
            'productType' => ['required', Rule::enum(ProductType::class)],
            'taxClass' => ['required', Rule::enum(TaxClass::class)],
            'sellingPrice' => $this->canSetPrices() ? ['required', $money, 'not_regex:/^0+(\.0+)?$/'] : ['nullable'],
            'buyingPrice' => ['nullable', 'regex:/^\d{1,14}(\.\d{1,4})?$/'],
            'minStockLevel' => ['required', $qty],
            'reorderLevel' => ['required', $qty],
            'barcode' => ['nullable', 'string', 'max:64'],
            'barcodeType' => ['required', Rule::in([BarcodeType::Manufacturer->value, BarcodeType::Internal->value])],
            'extraBarcodes.*' => ['nullable', 'string', 'max:64'],
            'packaging.*.unit' => ['required', 'uuid'],
            'packaging.*.factor' => ['nullable', 'regex:/^\d{1,9}(\.\d{1,6})?$/'],
            'packaging.*.barcode' => ['nullable', 'string', 'max:64'],
            'packaging.*.price' => ['nullable', $money],
            // Image: extension + MIME sniffing + size here; decoded and re-encoded by ProductImageService.
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('inventory.product_images.max_kilobytes')],
        ], [
            'sellingPrice.required' => __('Enter the selling price.'),
            'sellingPrice.not_regex' => __('The selling price must be more than zero.'),
            'packaging.*.unit.required' => __('Select a unit.'),
        ], [
            'sellingPrice' => __('Selling price'), 'buyingPrice' => __('Buying price'), 'baseUnit' => __('Base unit'),
        ]);

        // Idempotency: the first request with this key wins; repeats are ignored.
        $lock = 'product-form:'.auth()->id().':'.$this->submissionKey;
        if (! Cache::add($lock, 'pending', now()->addMinutes(10))) {
            $done = Cache::get($lock);

            return ($done && $done !== 'pending' && $then === 'show') ? $this->redirectRoute('catalogue.products.show', $done, navigate: false) : null;
        }

        $data = [
            'name' => $this->name,
            'sku' => $this->sku,
            'description' => $this->description,
            'category_id' => Category::query()->where('uuid', $this->category)->value('id'),
            'brand_id' => $this->brand ? Brand::query()->where('uuid', $this->brand)->value('id') : null,
            'base_unit_id' => Unit::query()->where('uuid', $this->baseUnit)->value('id'),
            'product_type' => $this->productType,
            'tax_class' => $this->taxClass,
            'reference_buying_price' => $this->buyingPrice,
            'min_stock_level' => $this->minStockLevel,
            'reorder_level' => $this->reorderLevel,
            'track_expiry' => $this->trackExpiry,
            'track_batches' => $this->trackBatches,
        ];

        try {
            $product = DB::transaction(function () use ($products, $images, $barcodes, $data, $editing) {
                if ($editing) {
                    $product = $products->update(Product::query()->where('uuid', $this->productUuid)->firstOrFail(), $data, $this->actor());
                    if ($this->canSetPrices()) {
                        $products->changeBasePrice($product, $this->sellingPrice, $this->actor());
                    }
                    // A barcode can be ADDED here when the product has none; existing
                    // barcode/unit mappings are managed on Product details.
                    if ($this->barcode !== '' && ! ProductBarcode::query()->where('product_id', $product->id)->exists()) {
                        $barcodes->add($product, $this->barcode, BarcodeType::from($this->barcodeType), null, true);
                    }
                } else {
                    $product = $products->create($this->company(), $data + [
                        'selling_price' => '0',
                        'initial_price' => $this->canSetPrices() ? $this->sellingPrice : null,
                        'barcode' => $this->barcode,
                        'barcode_type' => $this->barcodeType,
                        'generate_barcode' => $this->generateBarcode && $this->barcode === '',
                        'extra_barcodes' => $this->extraBarcodes,
                        'packaging' => collect($this->packaging)->map(fn ($p) => [
                            'unit_id' => Unit::query()->where('uuid', $p['unit'])->value('id'),
                            'factor' => $p['factor'], 'barcode' => $p['barcode'],
                            'price' => $this->canSetPrices() ? $p['price'] : null,
                            'can_purchase' => (bool) $p['purchase'], 'can_sell' => (bool) $p['sell'],
                        ])->all(),
                    ], $this->actor());
                }

                if ($this->image !== null) {
                    $images->store($product, $this->image);
                }

                return $product;
            });
        } catch (\Throwable $e) {
            Cache::forget($lock);      // let the user fix the input and save again
            if ($e instanceof ValidationException) {
                $this->showAdvanced = $this->showAdvanced || collect($e->errors())->keys()
                    ->contains(fn ($k) => Str::startsWith($k, ['packaging', 'extra_barcodes', 'track_', 'min_stock', 'reorder', 'description']));
                throw ValidationException::withMessages($this->formErrors($e->errors()));
            }
            throw $e;
        }

        Cache::put($lock, $product->uuid, now()->addMinutes(10));

        if ($editing || $then !== 'another') {
            session()->flash('status', $editing ? __('Product saved.') : __('Product ":name" saved and ready to sell.', ['name' => $product->name]));

            return $this->redirectRoute('catalogue.products.show', $product, navigate: false);
        }

        $this->resetForNew(keepGrouping: true);
        $this->savedMessage = __('Product ":name" saved (SKU :sku).', ['name' => $product->name, 'sku' => $product->sku]);
        $this->savedUuid = $product->uuid;
        $this->dispatch('product-saved');

        return null;
    }

    /** Map service error keys (snake_case) onto the form's fields. */
    private function formErrors(array $errors): array
    {
        $map = ['category_id' => 'category', 'brand_id' => 'brand', 'base_unit_id' => 'baseUnit', 'initial_price' => 'sellingPrice',
            'selling_price' => 'sellingPrice', 'reference_buying_price' => 'buyingPrice', 'min_stock_level' => 'minStockLevel',
            'reorder_level' => 'reorderLevel', 'track_batches' => 'trackBatches', 'barcode_type' => 'barcodeType', 'extra_barcodes' => 'extraBarcodes'];

        return collect($errors)->mapWithKeys(function ($messages, $key) use ($map) {
            [$head, $rest] = array_pad(explode('.', $key, 2), 2, null);
            $head = $map[$head] ?? $head;

            return [$rest !== null ? "{$head}.{$rest}" : $head => $messages];
        })->all();
    }

    public function render(): View
    {
        $editing = $this->productUuid !== null;
        $product = $editing ? Product::query()->where('uuid', $this->productUuid)->firstOrFail() : null;
        $user = auth()->user();
        $baseUnit = Unit::query()->where('uuid', $this->baseUnit)->first();

        return view('livewire.catalogue.product-form', [
            'categories' => Category::query()->where('is_active', true)->orderBy('name')->get(['uuid', 'name']),
            'brands' => Brand::query()->where('is_active', true)->orderBy('name')->get(['uuid', 'name']),
            'units' => Unit::query()->where('is_active', true)->orderBy('dimension')->orderBy('name')->get(['uuid', 'name', 'code', 'dimension']),
            'baseUnitModel' => $baseUnit,
            'types' => ProductType::cases(),
            'taxClasses' => TaxClass::cases(),
            'barcodeTypes' => [BarcodeType::Manufacturer, BarcodeType::Internal],
            'editing' => $editing,
            'product' => $product,
            'existingBarcodes' => $product ? ProductBarcode::query()->where('product_id', $product->id)->with('productUnit.unit')->orderByDesc('is_primary')->get() : collect(),
            'structureLocked' => $product !== null && app(ProductService::class)->hasTransactions($product),
            'currency' => $this->company()->currency,
            'can' => [
                'prices' => $this->canSetPrices(),
                'categories' => $user->can('categories.manage'),
                'brands' => $user->can('brands.manage'),
            ],
        ])->title($editing ? __('Edit product') : __('Add product'));
    }
}
