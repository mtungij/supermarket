<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Services\Pos\SalesQueries;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Browser-printed thermal receipt (58 mm / 80 mm). Paper width comes from
 * ?width=, else the sale's POS device setting, else the company default.
 * {sale} resolves through the tenant scope (other companies: 404) and the
 * viewer must be allowed to see the sale (branch / own sales).
 */
class ReceiptController extends Controller
{
    public function __invoke(Request $request, Sale $sale, SalesQueries $sales, TenantContext $context): View
    {
        abort_unless($sales->canSee($request->user(), $sale), 403);
        $validated = $request->validate(['width' => ['nullable', 'in:58,80'], 'autoprint' => ['nullable', 'boolean']]);

        $sale->load(['items', 'payments', 'branch', 'cashier:id,name', 'device:id,name,receipt_paper_width', 'customer:id,name,phone']);
        $company = $context->company();

        $width = (int) ($validated['width'] ?? $sale->device?->receipt_paper_width ?? data_get($company->settings, 'receipt_paper_width', 80));

        return view('sales.receipt', [
            'sale' => $sale,
            'company' => $company,
            'width' => in_array($width, [58, 80], true) ? $width : 80,
            'autoprint' => (bool) ($validated['autoprint'] ?? false),
            'footer' => data_get($company->settings, 'receipt_footer') ?: __('Thank you for shopping with us!'),
            'tz' => $company->timezone,
        ]);
    }
}
