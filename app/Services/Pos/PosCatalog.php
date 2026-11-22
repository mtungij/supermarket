<?php

namespace App\Services\Pos;

use App\Exceptions\BusinessRuleException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockBalance;
use App\Models\StockLocation;
use App\Services\Catalogue\BarcodeService;
use App\Services\Catalogue\PriceService;
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

        return new PosItem($product, $unit, $this->prices->resolve($product, $unit, $branch), $barcode);
    }

    /** Name / SKU / exact barcode search among active products. */
    public function search(Company $company, string $term, int $limit = 10): Collection
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return collect();
        }

        $like = '%'.addcslashes($term, '%_').'%';
        $upper = strtoupper($term);

        return Product::forCompany($company)->where('is_active', true)
            ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('sku', 'like', addcslashes($upper, '%_').'%')
                ->orWhereHas('barcodes', fn ($b) => $b->where('barcode', BarcodeService::normalize($term))))
            ->with('baseUnit:id,name')->orderBy('name')->limit($limit)->get();
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
