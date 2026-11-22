<?php

namespace App\Http\Controllers\Catalogue;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Services\Catalogue\PriceService;
use App\Support\Barcode\BarcodeSvg;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Print-ready shelf/product labels (browser print, A4 grid or label roll). */
class ProductLabelController extends Controller
{
    public function __invoke(Request $request, Product $product, PriceService $prices, TenantContext $context): View
    {
        $this->authorize('products.view');
        $validated = $request->validate([
            'barcode' => ['nullable', 'integer'],
            'copies' => ['nullable', 'integer', 'min:1', 'max:200'],
            'layout' => ['nullable', 'in:a4,roll'],
        ]);

        $barcodes = ProductBarcode::query()->where('product_id', $product->id)->with('productUnit.unit')->orderByDesc('is_primary')->get();
        $barcode = isset($validated['barcode']) ? $barcodes->firstWhere('id', $validated['barcode']) : $barcodes->first();
        abort_if($barcode === null, 404, __('Add a barcode to this product first.'));

        return view('catalogue.labels', [
            'product' => $product,
            'barcode' => $barcode,
            'barcodes' => $barcodes,
            'svg' => BarcodeSvg::svg($barcode->barcode),
            'price' => $prices->resolve($product, $barcode->productUnit, $context->branch())->price,
            'copies' => (int) ($validated['copies'] ?? 12),
            'layout' => $validated['layout'] ?? 'a4',
            'currency' => $context->company()->currency,
        ]);
    }
}
