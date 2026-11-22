<?php

namespace App\Livewire\Concerns;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Services\Catalogue\BarcodeService;
use App\Support\Decimal;
use Illuminate\Support\Collection;

/**
 * Barcode-first product picker for document forms (purchases, transfers,
 * adjustments).
 *
 * - Scanners that "type" the code and append Enter, and manual Enter, both
 *   call scanBarcode(): the input handles Enter itself and is never placed
 *   inside a <form> (a nested form made Enter submit the outer form).
 * - The code is resolved with the tenant-scoped BarcodeService::lookup, so
 *   other companies' barcodes are never found.
 * - A new product is added with quantity 1; scanning the same product+unit
 *   again increments that line instead of adding a duplicate row.
 * - The input is cleared and focus restored after every scan.
 *
 * Using components keep their rows in $lines and implement newLine();
 * they may override canAdd() to veto an addition (e.g. stock checks).
 */
trait PicksProducts
{
    public string $productSearch = '';

    public string $scan = '';

    /** Human feedback for the last successful scan. */
    public string $scanMessage = '';

    /** Row data for a newly added product (quantity is set to 1 by the trait). */
    abstract protected function newLine(Product $product, ProductUnit $unit): array;

    /** Return an error message to refuse adding $quantity (entered unit), or null. */
    protected function canAdd(Product $product, ProductUnit $unit, string $quantity): ?string
    {
        return null;
    }

    public function productResults(): Collection
    {
        $term = trim($this->productSearch);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        return Product::query()->where('is_active', true)
            ->where(fn ($q) => $q->where('name', 'like', '%'.addcslashes($term, '%_').'%')->orWhere('sku', 'like', addcslashes(strtoupper($term), '%_').'%'))
            ->with('baseUnit:id,name')
            ->orderBy('name')->limit(8)->get();
    }

    public function pickProduct(int $productId): void
    {
        $product = Product::query()->where('is_active', true)->findOrFail($productId);
        $this->addOrIncrement($product, $this->unitsFor($product->id)->firstWhere('is_base', true), 'productSearch');
        $this->productSearch = '';
        $this->dispatch('scan-focus');
    }

    public function scanBarcode(BarcodeService $barcodes): void
    {
        $code = trim($this->scan);
        $this->scan = '';
        $this->scanMessage = '';
        $this->resetErrorBag('scan');
        $this->dispatch('scan-focus');

        if ($code === '') {
            return;
        }

        $match = $barcodes->lookup($this->company(), $code);

        if ($match === null || ! $match['product']->is_active || ! $match['product_unit']?->is_active) {
            $this->addError('scan', __('No active product found for :code.', ['code' => $code]));

            return;
        }

        $this->addOrIncrement($match['product'], $match['product_unit'], 'scan');
    }

    protected function addOrIncrement(Product $product, ProductUnit $unit, string $errorField): bool
    {
        $this->resetErrorBag($errorField);

        foreach ($this->lines as $i => $line) {
            if ((int) $line['product_id'] === $product->id && (int) $line['product_unit_id'] === $unit->id) {
                $current = Decimal::isValid((string) $line['quantity']) ? (string) $line['quantity'] : '0';
                $next = Decimal::trim(Decimal::add($current, '1', Decimal::QTY));

                if (($error = $this->canAdd($product, $unit, $next)) !== null) {
                    $this->addError($errorField, $error);

                    return false;
                }

                $this->lines[$i]['quantity'] = $next;
                $this->scanMessage = __(':product: quantity :qty', ['product' => $product->name, 'qty' => $next]);

                return true;
            }
        }

        if (($error = $this->canAdd($product, $unit, '1')) !== null) {
            $this->addError($errorField, $error);

            return false;
        }

        $this->lines[] = array_merge($this->newLine($product, $unit), ['quantity' => '1']);
        $this->scanMessage = __('Added :product.', ['product' => $product->name]);

        return true;
    }

    /** Active units configured for a product (tenant-scoped). */
    public function unitsFor(int $productId): Collection
    {
        return ProductUnit::query()->where('product_id', $productId)->where('is_active', true)
            ->with('unit:id,name,code')->orderByDesc('is_base')->orderBy('factor')->get();
    }

    protected function productName(int $productId): string
    {
        return (string) Product::query()->whereKey($productId)->value('name');
    }
}
