<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV template for opening-stock import, pre-filled with the company's
 * active products (SKU, barcode, name, base unit). Rows left without a
 * quantity are skipped on import. Cells are neutralised against
 * spreadsheet formula injection.
 */
class OpeningStockTemplateController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        abort_unless($request->user()->can('inventory.opening.manage'), 403);

        // Resolve the tenant NOW: the stream callback runs after the request
        // pipeline, when the tenant context may already be cleared.
        $companyId = app(TenantContext::class)->companyId() ?? abort(403);

        return response()->streamDownload(function () use ($companyId) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // Excel: UTF-8
            fputcsv($out, ['sku', 'barcode', 'product_name', 'unit', 'quantity', 'unit_cost', 'batch_number', 'manufactured_on', 'expires_on'], ',', '"', '');

            Product::forCompany($companyId)->where('is_active', true)->with(['baseUnit:id,code', 'barcodes' => fn ($q) => $q->orderByDesc('is_primary')])
                ->orderBy('name')->chunk(500, function ($products) use ($out) {
                    foreach ($products as $p) {
                        fputcsv($out, array_map([self::class, 'safe'], [
                            $p->sku, (string) $p->barcodes->first()?->barcode, $p->name, (string) $p->baseUnit?->code, '', '', '', '', '',
                        ]), ',', '"', '');
                    }
                });
            fclose($out);
        }, 'opening-stock-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function safe(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
