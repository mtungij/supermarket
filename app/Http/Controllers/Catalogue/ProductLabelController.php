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

/**
 * Print-ready product labels (browser print).
 *
 * Layouts:
 *  - a4             A4 sheet grid (office printers)
 *  - roll           generic 50x30 mm label roll
 *  - thermal-70x37  thermal gap labels 70 x 37 mm (e.g. ROMESON ZJ80UEB,
 *                   74 mm media, 2 mm gap): one label per printed page,
 *                   CSS page size = the sticker, margin 0, no browser
 *                   header/footer, barcode at an exact physical size
 *  - thermal-37x70  the same label rotated 90 degrees, for printers/drivers
 *                   that feed the roll the other way round
 *
 * The last layout used is remembered in the session.
 */
class ProductLabelController extends Controller
{
    public const LAYOUTS = ['a4', 'roll', 'thermal-70x37', 'thermal-37x70'];

    /** 203 dpi thermal head = 8 dots/mm: 0.5 mm = 4 dots per module (EAN-13 X = 0.5 mm). */
    private const THERMAL_MODULE_MM = 0.5;

    private const THERMAL_BAR_HEIGHT_MM = 12.0;

    private const THERMAL_MAX_BARCODE_MM = 64.0;

    public function __invoke(Request $request, Product $product, PriceService $prices, TenantContext $context): View
    {
        $this->authorize('products.view');
        $validated = $request->validate([
            'barcode' => ['nullable', 'integer'],
            'copies' => ['nullable', 'integer', 'min:1', 'max:200'],
            'layout' => ['nullable', 'in:'.implode(',', self::LAYOUTS)],
            'autoprint' => ['nullable', 'boolean'],
        ]);

        $layout = $validated['layout'] ?? $request->session()->get('labels.layout', 'a4');
        $request->session()->put('labels.layout', $layout);

        $barcodes = ProductBarcode::query()->where('product_id', $product->id)->with('productUnit.unit')->orderByDesc('is_primary')->get();
        $barcode = isset($validated['barcode']) ? $barcodes->firstWhere('id', $validated['barcode']) : $barcodes->first();
        abort_if($barcode === null, 404, __('Add a barcode to this product first.'));

        $data = [
            'product' => $product,
            'barcode' => $barcode,
            'barcodes' => $barcodes,
            'price' => $prices->resolve($product, $barcode->productUnit, $context->branch())->price,
            'unit' => __($barcode->productUnit->unit->name),
            'layout' => $layout,
            'layouts' => self::LAYOUTS,
            'currency' => $context->company()->currency,
            'autoprint' => (bool) ($validated['autoprint'] ?? false),
        ];

        if (str_starts_with($layout, 'thermal-')) {
            $module = self::THERMAL_MODULE_MM;
            $svg = BarcodeSvg::svgMm($barcode->barcode, self::THERMAL_BAR_HEIGHT_MM, $module, self::THERMAL_MAX_BARCODE_MM);

            return view('catalogue.labels-thermal', $data + [
                'svg' => $svg,
                'moduleMm' => $module,
                'copies' => (int) ($validated['copies'] ?? 1),
                'rotated' => $layout === 'thermal-37x70',
            ]);
        }

        return view('catalogue.labels', $data + [
            'svg' => BarcodeSvg::svg($barcode->barcode),
            'copies' => (int) ($validated['copies'] ?? 12),
        ]);
    }
}
