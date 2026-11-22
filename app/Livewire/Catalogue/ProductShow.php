<?php

namespace App\Livewire\Catalogue;

use App\Enums\BarcodeType;
use App\Livewire\Concerns\InteractsWithTenant;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductPrice;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Services\Catalogue\BarcodeService;
use App\Services\Catalogue\PriceService;
use App\Services\Catalogue\ProductService;
use App\Services\Catalogue\UnitConversionService;
use App\Services\Inventory\InventoryQueries;
use App\Services\Inventory\StockLedger;
use App\Support\Decimal;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('components.layouts.tenant')]
class ProductShow extends Component
{
    use InteractsWithTenant;

    #[Locked]
    public string $productUuid;

    // Unit conversion form
    public string $unitUuid = '';

    public string $factor = '';

    public bool $canPurchase = true;

    public bool $canSell = true;

    // Barcode form
    public string $barcode = '';

    public string $barcodeType = 'manufacturer';

    public string $barcodeUnit = '';

    // Price form
    public string $priceUnit = '';

    public string $priceBranch = '';

    public string $price = '';

    public string $effectiveFrom = '';

    public function mount(Product $product): void
    {
        $this->authorize('products.view');
        $this->productUuid = $product->uuid;
    }

    private function product(): Product
    {
        return Product::query()->where('uuid', $this->productUuid)->with(['baseUnit', 'category', 'brand'])->firstOrFail();
    }

    private function productUnit(string|int $id): ProductUnit
    {
        return ProductUnit::query()->where('product_id', $this->product()->id)->findOrFail((int) $id);
    }

    public function saveUnit(UnitConversionService $conversions): void
    {
        $this->authorize('products.update');
        $this->validate(['unitUuid' => ['required', 'uuid'], 'factor' => ['nullable', 'string', 'max:20']]);
        $unit = Unit::query()->where('uuid', $this->unitUuid)->firstOrFail();

        $conversions->setUnit($this->product(), $unit, $this->factor === '' ? null : $this->factor, $this->canPurchase, $this->canSell);
        $this->reset(['unitUuid', 'factor']);
        session()->flash('status', __('Unit conversion saved.'));
    }

    public function removeUnit(int $id, UnitConversionService $conversions): void
    {
        $this->authorize('products.update');
        $conversions->deactivate($this->productUnit($id));
    }

    public function addBarcode(BarcodeService $barcodes): void
    {
        $this->authorize('products.update');
        $this->validate(['barcode' => ['required', 'string', 'max:64'], 'barcodeType' => ['required', Rule::enum(BarcodeType::class)]]);

        $unit = $this->barcodeUnit === '' ? null : $this->productUnit($this->barcodeUnit);
        $barcodes->add($this->product(), $this->barcode, BarcodeType::from($this->barcodeType), $unit);
        $this->reset(['barcode', 'barcodeUnit']);
    }

    public function generateBarcode(BarcodeService $barcodes): void
    {
        $this->authorize('products.update');
        $unit = $this->barcodeUnit === '' ? null : $this->productUnit($this->barcodeUnit);
        $barcodes->generateInternal($this->product(), $unit);
    }

    public function makePrimary(int $id, BarcodeService $barcodes): void
    {
        $this->authorize('products.update');
        $barcodes->makePrimary(ProductBarcode::query()->where('product_id', $this->product()->id)->findOrFail($id));
    }

    public function removeBarcode(int $id, BarcodeService $barcodes): void
    {
        $this->authorize('products.update');
        $barcodes->remove(ProductBarcode::query()->where('product_id', $this->product()->id)->findOrFail($id));
    }

    public function savePrice(PriceService $prices): void
    {
        $this->authorize('products.prices.manage');
        $this->validate([
            'priceUnit' => ['required', 'integer'],
            'priceBranch' => ['nullable', 'uuid'],
            'price' => ['required', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'effectiveFrom' => ['nullable', 'date'],
        ]);

        $branch = null;
        if ($this->priceBranch !== '') {
            $branch = $this->accessibleBranches()->first(fn (Branch $b) => hash_equals($b->uuid, $this->priceBranch));
            abort_if($branch === null, 403);
        }

        $from = $this->effectiveFrom === '' ? null
            : Carbon::parse($this->effectiveFrom, $this->company()->timezone)->utc();

        $prices->setPrice($this->product(), $this->productUnit($this->priceUnit), $this->price, $branch, $this->actor(), $from);
        $this->reset(['price', 'effectiveFrom']);
        session()->flash('status', __('Price saved.'));
    }

    public function toggleActive(ProductService $products): void
    {
        $this->authorize('products.deactivate');
        $product = $this->product();
        $products->setActive($product, ! $product->is_active, $this->actor());
    }

    public function render(): View
    {
        [$prices, $inventory, $ledger] = [app(PriceService::class), app(InventoryQueries::class), app(StockLedger::class)];
        $product = $this->product();
        $units = ProductUnit::query()->where('product_id', $product->id)->with('unit')->orderByDesc('is_base')->orderBy('factor')->get();
        $branch = app(TenantContext::class)->branch();
        $base = $units->firstWhere('is_base', true);

        $quotes = $units->where('is_active', true)->where('can_sell', true)
            ->mapWithKeys(fn (ProductUnit $u) => [$u->id => $prices->resolve($product, $u, $branch)]);

        $averageCost = $branch ? $ledger->averageCost($product->company_id, $branch->id, $product->id) : '0';
        $basePrice = $quotes[$base->id]->price ?? '0';
        $margin = Decimal::sub($basePrice, $averageCost !== '0.0000' ? $averageCost : (string) $product->purchase_cost, 4);

        return view('livewire.catalogue.product-show', [
            'product' => $product,
            'units' => $units,
            'availableUnits' => Unit::query()->where('is_active', true)->whereNotIn('id', $units->where('is_active', true)->pluck('unit_id'))->orderBy('name')->get(),
            'barcodes' => ProductBarcode::query()->where('product_id', $product->id)->with('productUnit.unit')->orderByDesc('is_primary')->get(),
            'quotes' => $quotes,
            'priceHistory' => ProductPrice::query()->where('product_id', $product->id)->with(['productUnit.unit', 'branch:id,name', 'creator:id,name'])->latest('effective_from')->latest('id')->limit(25)->get(),
            'branch' => $branch,
            'branches' => $this->accessibleBranches(),
            'balances' => $branch ? $inventory->productBalances($product, $branch) : collect(),
            'averageCost' => $averageCost,
            'basePrice' => $basePrice,
            'margin' => $margin,
            'marginPercent' => Decimal::isPositive($basePrice) ? Decimal::round(Decimal::div(Decimal::mul($margin, '100'), $basePrice), 1) : null,
            'barcodeTypes' => BarcodeType::cases(),
            'can' => [
                'update' => auth()->user()->can('products.update'),
                'prices' => auth()->user()->can('products.prices.manage'),
                'deactivate' => auth()->user()->can('products.deactivate'),
                'inventory' => auth()->user()->can('inventory.view'),
            ],
        ])->title($product->name);
    }
}
