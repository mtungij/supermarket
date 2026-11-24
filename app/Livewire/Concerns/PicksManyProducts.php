<?php

namespace App\Livewire\Concerns;

use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\StockBalance;
use App\Services\Catalogue\BarcodeService;
use App\Support\Decimal;
use Illuminate\Support\Collection;

/**
 * Searchable MULTI-select product picker (modal) for document forms.
 * Works next to PicksProducts (barcode scan box), which is unchanged.
 *
 * - Server-side search by name (every word), SKU or barcode; company scoped
 *   by the tenant CompanyScope; bounded pages of 25 ("Show more").
 * - Ticked products stay ticked while searching for others.
 * - Products already in the document (same default unit) are shown as
 *   "Added" and cannot be ticked again; addPicked() also skips them.
 * - Every pick is re-resolved server-side (active, this company); the
 *   using component's canAdd() can still refuse it with a reason.
 *
 * Using components keep rows in $lines, implement newLine() (from
 * PicksProducts) and pickerDefaultUnit(), and may override pickerStockScope().
 */
trait PicksManyProducts
{
    public bool $pickerOpen = false;

    public string $pickerSearch = '';

    /** @var array<int, true> product id => ticked */
    public array $pickerSelected = [];

    public int $pickerLimit = 25;

    public string $pickerMessage = '';

    /** The unit a picked product is added in (e.g. base, or first purchasable). */
    abstract protected function pickerDefaultUnit(Product $product): ?ProductUnit;

    /**
     * Where "In stock" is counted: ['location', id] or ['branch', id]; null hides it.
     *
     * @return array{0: string, 1: int}|null
     */
    protected function pickerStockScope(): ?array
    {
        return null;
    }

    public function openPicker(): void
    {
        $this->pickerOpen = true;
        $this->pickerMessage = '';
        $this->pickerLimit = 25;
    }

    public function closePicker(): void
    {
        $this->pickerOpen = false;
        $this->dispatch('scan-focus');
    }

    public function updatedPickerSearch(): void
    {
        $this->pickerLimit = 25;
    }

    public function togglePicked(int $productId): void
    {
        if (isset($this->pickerSelected[$productId])) {
            unset($this->pickerSelected[$productId]);
        } elseif (count($this->pickerSelected) < 200 && Product::query()->whereKey($productId)->where('is_active', true)->exists()) {
            $this->pickerSelected[$productId] = true;
        }
    }

    public function clearPicked(): void
    {
        $this->pickerSelected = [];
    }

    public function showMorePicker(): void
    {
        $this->pickerLimit = min($this->pickerLimit + 25, 200);
    }

    /** Add every ticked product (quantity 1) and report what happened. */
    public function addPicked(): void
    {
        $products = Product::query()->whereIn('id', array_keys($this->pickerSelected))->where('is_active', true)->orderBy('name')->get();
        [$added, $already, $refused] = [0, 0, []];

        foreach ($products as $product) {
            $unit = $this->pickerDefaultUnit($product);
            if ($unit === null) {
                $refused[] = $product->name.': '.__('no suitable unit');

                continue;
            }
            if (collect($this->lines)->contains(fn ($l) => (int) $l['product_id'] === $product->id && (int) $l['product_unit_id'] === $unit->id)) {
                $already++;

                continue;
            }
            if (($error = $this->canAdd($product, $unit, '1')) !== null) {
                $refused[] = $error;

                continue;
            }
            $this->lines[] = array_merge($this->newLine($product, $unit), ['quantity' => '1']);
            $added++;
        }

        $this->pickerSelected = [];
        $this->pickerOpen = $refused !== [];        // keep it open only to show why something was refused
        $this->pickerMessage = trim(implode(' ', array_filter([
            $added ? trans_choice(':count product added.|:count products added.', $added) : null,
            $already ? trans_choice(':count was already in the list.|:count were already in the list.', $already) : null,
            $refused ? __('Not added: :list', ['list' => implode('; ', $refused)]) : null,
        ])));
        $this->scanMessage = $added ? $this->pickerMessage : $this->scanMessage;
        if (! $this->pickerOpen) {
            $this->dispatch('scan-focus');
        }
    }

    /**
     * One bounded page of results plus whether more exist.
     *
     * @return array{rows: Collection, more: bool, stock: array<int, string>, inList: array<int, true>, showStock: bool}
     */
    public function pickerResults(): array
    {
        if (! $this->pickerOpen) {
            return ['rows' => collect(), 'more' => false, 'stock' => [], 'inList' => [], 'showStock' => false];
        }

        $term = trim(preg_replace('/\s+/u', ' ', $this->pickerSearch));
        $esc = fn (string $v) => addcslashes($v, '%_\\');
        $code = BarcodeService::normalize($term);

        $query = Product::query()->where('products.is_active', true)
            ->when($term !== '', function ($q) use ($term, $esc, $code) {
                $q->where(function ($w) use ($term, $esc, $code) {
                    $w->where(function ($n) use ($term, $esc) {
                        foreach (explode(' ', $term) as $word) {
                            $n->where('products.name', 'like', '%'.$esc($word).'%');
                        }
                    })->orWhere('products.sku', 'like', '%'.$esc($code).'%')
                        ->orWhereIn('products.id', ProductBarcode::query()->where('barcode', 'like', $esc($code).'%')->select('product_id'));
                });
            })
            ->with(['baseUnit:id,name', 'barcodes' => fn ($b) => $b->orderByDesc('is_primary')->select('id', 'product_id', 'barcode', 'is_primary')])
            ->orderBy('products.name')->orderBy('products.id');

        $rows = $query->limit($this->pickerLimit + 1)->get();
        $more = $rows->count() > $this->pickerLimit;
        $rows = $rows->take($this->pickerLimit);

        $stock = [];
        if (($scope = $this->pickerStockScope()) !== null && $rows->isNotEmpty()) {
            $stock = StockBalance::query()->where($scope[0] === 'location' ? 'location_id' : 'branch_id', $scope[1])
                ->whereIn('product_id', $rows->pluck('id'))->groupBy('product_id')
                ->selectRaw('product_id, sum(quantity) as qty')->pluck('qty', 'product_id')
                ->map(fn ($v) => Decimal::trim(Decimal::round((string) $v, Decimal::QTY)))->all();
        }

        $inList = [];
        foreach ($this->lines as $l) {
            $inList[(int) $l['product_id']] = true;
        }

        return ['rows' => $rows, 'more' => $more, 'stock' => $stock, 'inList' => $inList, 'showStock' => $scope !== null];
    }
}
