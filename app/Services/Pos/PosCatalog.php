<?php

namespace App\Services\Pos;

use App\Exceptions\BusinessRuleException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\StockBalance;
use App\Models\StockLocation;
use App\Services\Catalogue\BarcodeService;
use App\Services\Catalogue\PriceService;
use App\Support\Decimal;
use Illuminate\Support\Collection;

/**
 * POS product rules in ONE place (reused by checkout and, later, by the
 * offline catalogue export):
 *   - tenant-scoped barcode/SKU lookup (manufacturer, internal and
 *     packaging barcodes -> their product unit)
 *   - inactive products / units and units not marked "sell" are refused
 *   - the selling price is resolved server-side (effective-dated, branch
 *     and unit aware); the client never supplies prices
 *   - availability counts only the selling location and never expired lots
 */
class PosCatalog
{
    public function __construct(
        private readonly BarcodeService $barcodes,
        private readonly PriceService $prices,
    ) {}

    /** @throws BusinessRuleException when the code is unknown or not sellable */
    public function lookup(Company $company, Branch $branch, string $code): PosItem
    {
        $match = $this->barcodes->lookup($company, $code);

        if ($match === null) {
            throw BusinessRuleException::make('scan', __('No product found for :code.', ['code' => trim($code)]));
        }

        return $this->item($match['product'], $match['product_unit'], $branch, $match['barcode']?->barcode);
    }

    public function item(Product $product, ProductUnit $unit, Branch $branch, ?string $barcode = null): PosItem
    {
        if (! $product->is_active) {
            throw BusinessRuleException::make('scan', __(':product is inactive and cannot be sold.', ['product' => $product->name]));
        }

        if (! $unit->is_active || ! $unit->can_sell || $unit->product_id !== $product->id) {
            throw BusinessRuleException::make('scan', __(':product cannot be sold in this unit.', ['product' => $product->name]));
        }

        $price = $this->prices->resolve($product, $unit, $branch);

        // A product registered without a price (e.g. by a store keeper who may
        // not set prices) is never sold for free by accident.
        if (! Decimal::isPositive($price->price)) {
            throw BusinessRuleException::make('scan', __(':product has no selling price yet. Ask a manager to set the price.', ['product' => $product->name]));
        }

        return new PosItem($product, $unit, $price, $barcode);
    }

    /**
     * POS product search (name, SKU or barcode) for products WITHOUT having
     * to scan. Company-scoped (fail-closed CompanyScope + explicit company),
     * active products only, at most $limit rows, ranked:
     *   0 exact barcode / SKU, 1 name starts with the term,
     *   2 a word in the name starts with the term, 3 anything else.
     * Every word typed must appear in the name ("soap family" finds
     * FAMILY MEDICATED SOAP). Matching is case-insensitive (MySQL _ci
     * collation; SKUs/barcodes are stored upper-case).
     *
     * Each row carries the default selling unit, its server-resolved
     * (effective-dated, branch-aware) price and the quantity available at
     * the register's SELLING location only (never receiving/storage, never
     * expired lots). Rows are display data: adding to the cart re-resolves
     * everything server-side.
     *
     * @return list<array{uuid: string, name: string, sku: string, barcode: ?string, exact: bool, unit: ?string,
     *     price: ?string, available: string, base_unit: string, status: string}>
     */
    public function searchSellable(Company $company, Branch $branch, ?StockLocation $location, string $term, int $limit = 12): array
    {
        $term = trim((string) preg_replace('/\s+/u', ' ', $term));
        if (mb_strlen($term) < 2 || mb_strlen($term) > 64) {
            return [];
        }

        $code = BarcodeService::normalize($term);
        $isCode = (bool) preg_match('/^[A-Z0-9\-\.]+$/', $code);
        $esc = fn (string $v) => addcslashes($v, '%_\\');
        $words = array_values(array_filter(explode(' ', $term), fn ($w) => $w !== ''));

        $exactBarcode = ProductBarcode::forCompany($company)->where('barcode', $code)->select('product_id');

        $products = Product::forCompany($company)->where('products.is_active', true)
            ->where(function ($q) use ($words, $esc, $code, $isCode, $company) {
                $q->where(function ($n) use ($words, $esc) {
                    foreach ($words as $w) {
                        $n->where('products.name', 'like', '%'.$esc($w).'%');
                    }
                });
                if ($isCode) {
                    $q->orWhere('products.sku', 'like', '%'.$esc($code).'%')
                        ->orWhereIn('products.id', ProductBarcode::forCompany($company)->where('barcode', 'like', $esc($code).'%')->select('product_id'));
                }
            })
            ->select('products.id', 'products.uuid', 'products.name', 'products.sku', 'products.selling_price', 'products.base_unit_id', 'products.company_id', 'products.is_active')
            ->selectRaw('case when products.sku = ? or products.id in ('.$exactBarcode->toSql().') then 0
                    when products.name like ? then 1 when products.name like ? then 2 else 3 end as search_rank',
                array_merge([$code], $exactBarcode->getBindings(), [$esc($term).'%', '% '.$esc($term).'%']))
            ->with('baseUnit:id,name')
            ->orderBy('search_rank')->orderBy('products.name')->orderBy('products.id')
            ->limit($limit)->get();

        if ($products->isEmpty()) {
            return [];
        }

        $ids = $products->pluck('id');
        $units = ProductUnit::forCompany($company)->whereIn('product_id', $ids)->where('is_active', true)->where('can_sell', true)
            ->with('unit:id,name')->orderByDesc('is_base')->orderBy('factor')->get()->groupBy('product_id');
        $barcodes = ProductBarcode::forCompany($company)->whereIn('product_id', $ids)
            ->orderByRaw('barcode = ? desc', [$code])->orderByDesc('is_primary')->orderBy('id')
            ->get(['product_id', 'barcode'])->groupBy('product_id');
        $available = $location ? $this->availableMany($location, $ids->all()) : [];

        return $products->map(function (Product $p) use ($units, $barcodes, $available, $branch) {
            $unit = $units->get($p->id)?->first();
            $qty = $available[$p->id] ?? '0';

            return [
                'uuid' => $p->uuid,
                'name' => $p->name,
                'sku' => $p->sku,
                'barcode' => $barcodes->get($p->id)?->first()?->barcode,
                'exact' => (int) $p->search_rank === 0,
                'unit' => $unit ? __($unit->unit->name) : null,
                'price' => ($price = $unit ? $this->prices->resolve($p, $unit, $branch)->price : null) !== null ? Decimal::format($price) : null,
                'available' => Decimal::trim(Decimal::round($qty, Decimal::QTY)),
                'base_unit' => __($p->baseUnit->name),
                'status' => match (true) {
                    $unit === null => 'not_sellable',
                    ! Decimal::isPositive((string) $price) => 'no_price',
                    ! Decimal::isPositive($qty) || Decimal::cmp($qty, (string) $unit->factor) < 0 => 'out_of_stock',
                    default => 'in_stock',
                },
            ];
        })->values()->all();
    }

    /**
     * Sellable base quantity per product at the location in ONE query
     * (same rules as available(): positive balances, no expired lots).
     *
     * @param  list<int>  $productIds
     * @return array<int, string>
     */
    public function availableMany(StockLocation $location, array $productIds): array
    {
        return StockBalance::forCompany($location->company_id)
            ->where('stock_balances.location_id', $location->id)
            ->whereIn('stock_balances.product_id', $productIds)
            ->where('stock_balances.quantity', '>', 0)
            ->leftJoin('stock_batches as b', 'b.id', '=', 'stock_balances.batch_id')
            ->where(fn ($q) => $q->whereNull('stock_balances.batch_id')->orWhereNull('b.expires_on')->orWhere('b.expires_on', '>=', today()->toDateString()))
            ->groupBy('stock_balances.product_id')
            ->selectRaw('stock_balances.product_id, sum(stock_balances.quantity) as qty')
            ->pluck('qty', 'stock_balances.product_id')->map(fn ($v) => (string) $v)->all();
    }

    /** Sellable units of a product (base first). */
    public function units(Product $product): Collection
    {
        return ProductUnit::forCompany($product->company_id)->where('product_id', $product->id)
            ->where('is_active', true)->where('can_sell', true)->with('unit:id,name,code')
            ->orderByDesc('is_base')->orderBy('factor')->get();
    }

    /** Sellable base quantity at the location: never counts expired lots. */
    public function available(StockLocation $location, int $productId): string
    {
        return (string) ($this->sellableBalances($location, $productId)->sum('quantity') ?: '0');
    }

    /**
     * Balances that may be sold, in FEFO order (earliest expiry first,
     * lots without expiry last).
     */
    public function sellableBalances(StockLocation $location, int $productId, bool $lock = false)
    {
        return StockBalance::forCompany($location->company_id)
            ->where('stock_balances.location_id', $location->id)
            ->where('stock_balances.product_id', $productId)
            ->where('stock_balances.quantity', '>', 0)
            ->leftJoin('stock_batches as b', 'b.id', '=', 'stock_balances.batch_id')
            ->where(fn ($q) => $q->whereNull('stock_balances.batch_id')->orWhereNull('b.expires_on')->orWhere('b.expires_on', '>=', today()->toDateString()))
            ->select('stock_balances.*', 'b.expires_on')
            ->orderByRaw('b.expires_on is null')->orderBy('b.expires_on')->orderBy('stock_balances.id')
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get();
    }

    /** Expired quantity still sitting at the location (for clear warnings). */
    public function expiredQuantity(StockLocation $location, int $productId): string
    {
        return (string) (StockBalance::forCompany($location->company_id)->where('stock_balances.location_id', $location->id)
            ->where('stock_balances.product_id', $productId)->where('stock_balances.quantity', '>', 0)
            ->join('stock_batches as b', 'b.id', '=', 'stock_balances.batch_id')
            ->where('b.expires_on', '<', today()->toDateString())->sum('stock_balances.quantity') ?: '0');
    }
}
